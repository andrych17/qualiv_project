<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgDeadLetter;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowInstanceStep;
use App\Modules\WNE\Models\WrkflowSlaRule;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\SlaEscalationService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3G — API & Webhook Extensibility Engine. Outbound (`webhook_call`) rides
 * §3I/§3M's entire messaging pipeline as a fifth channel — no second retry/DLQ
 * implementation. Inbound (`wait_for_callback`) parks a step at `waiting_external` behind a
 * single-use token, resumed either by the token hitting the (session-less, tenant-in-URL)
 * callback endpoint, or by §3F's SLA sweep failing it via the new `fail_step` action.
 */
class WorkflowApiWebhookExtensibilityTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    private function publishedVersion(string $code): WrkflowVersion
    {
        $definition = WrkflowDefinition::query()->create(['code' => $code, 'name' => $code, 'status' => WrkflowDefinition::STATUS_PUBLISHED]);

        return WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $this->adminId()]);
    }

    // ---- Outbound: webhook_call ----------------------------------------------------------

    public function test_a_webhook_call_step_posts_the_rendered_payload_and_the_instance_completes(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 200, ['X-Request-Id' => 'req-1'])]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.webhook_direct');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WEBHOOK_CALL, 'is_entry_step' => true,
                'config' => ['url' => 'https://hooks.example.com/in', 'method' => 'POST', 'payload_template' => '{"case_id": {{case_id}}, "status": "{{status}}"}'],
            ]);

            $instance = app(WorkflowService::class)->start('demo.webhook_direct', null, null, ['case_id' => 42, 'status' => 'approved']);

            $this->assertSame(WrkflowInstance::STATUS_COMPLETED, $instance->fresh()->status);

            Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.com/in'
                && $request->method() === 'POST'
                && $request->data() === ['case_id' => 42, 'status' => 'approved']);

            $notification = MsgNotification::query()->where('category_code', "wne.webhook.{$step->step_code}")->first();
            $this->assertNotNull($notification);
            $this->assertSame(MsgNotification::RECIPIENT_WEBHOOK, $notification->recipient_type);
            $this->assertSame(MsgNotification::STATUS_SENT, $notification->fresh()->status);

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $notification->id)->firstOrFail();
            $this->assertSame('webhook', $delivery->channel);
            $this->assertSame('req-1', $delivery->provider_message_id);
        });
    }

    public function test_a_webhook_call_step_sends_the_encrypted_auth_headers(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 200)]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.webhook_auth');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WEBHOOK_CALL, 'is_entry_step' => true,
                'config' => ['url' => 'https://hooks.example.com/secure', 'payload_template' => '{}'],
                'webhook_auth_headers' => ['Authorization' => 'Bearer secret-token'],
            ]);

            app(WorkflowService::class)->start('demo.webhook_auth', null, null, []);

            Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
        });
    }

    public function test_webhook_auth_headers_are_stored_encrypted_at_rest(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.webhook_encrypted');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WEBHOOK_CALL, 'is_entry_step' => true,
                'config' => ['url' => 'https://hooks.example.com/x'],
                'webhook_auth_headers' => ['Authorization' => 'Bearer secret-token'],
            ]);

            $raw = \DB::table('WNE.wrkflow_steps')->where('id', $step->id)->value('webhook_auth_headers');

            $this->assertStringNotContainsString('secret-token', $raw);
            $this->assertSame(['Authorization' => 'Bearer secret-token'], $step->fresh()->webhook_auth_headers);
        });
    }

    public function test_a_webhook_call_step_with_no_url_throws(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.webhook_no_url');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WEBHOOK_CALL, 'is_entry_step' => true,
                'config' => ['payload_template' => '{}'],
            ]);

            $this->expectException(WorkflowEngineException::class);
            $this->expectExceptionMessage('no URL configured');

            app(WorkflowService::class)->start('demo.webhook_no_url', null, null, []);
        });
    }

    public function test_a_failing_webhook_with_no_retry_policy_fails_immediately_reusing_3m_unchanged(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['error' => 'boom'], 500)]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.webhook_fail_once');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WEBHOOK_CALL, 'is_entry_step' => true,
                'config' => ['url' => 'https://hooks.example.com/in', 'payload_template' => '{}'],
            ]);

            app(WorkflowService::class)->start('demo.webhook_fail_once', null, null, []);

            $notification = MsgNotification::query()->where('category_code', "wne.webhook.{$step->step_code}")->firstOrFail();
            $delivery = MsgNotificationDelivery::query()->where('notification_id', $notification->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->status);
            $this->assertSame(0, MsgDeadLetter::query()->count());
            Http::assertSentCount(1);
        });
    }

    public function test_a_failing_webhook_with_a_multi_attempt_policy_dead_letters_via_the_same_3m_pipeline(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['error' => 'down'], 500)]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            MsgChannelConfig::query()->create(['channel' => MsgChannelConfig::CHANNEL_WEBHOOK, 'enabled' => true, 'max_attempts' => 3, 'backoff_schedule' => [1, 1]]);

            $version = $this->publishedVersion('demo.webhook_dlq');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WEBHOOK_CALL, 'is_entry_step' => true,
                'config' => ['url' => 'https://hooks.example.com/in', 'payload_template' => '{}'],
            ]);

            app(WorkflowService::class)->start('demo.webhook_dlq', null, null, []);

            Http::assertSentCount(3);

            $notification = MsgNotification::query()->where('category_code', "wne.webhook.{$step->step_code}")->firstOrFail();
            $delivery = MsgNotificationDelivery::query()->where('notification_id', $notification->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_DEAD_LETTERED, $delivery->status);

            $deadLetter = MsgDeadLetter::query()->where('delivery_id', $delivery->id)->firstOrFail();
            $this->assertSame('webhook', $deadLetter->channel);
            $this->assertCount(3, $deadLetter->failure_history);
        });
    }

    // ---- Inbound: wait_for_callback -------------------------------------------------------

    public function test_a_wait_for_callback_step_parks_the_instance_with_a_token_and_due_at(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.wait_park');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WAIT_FOR_CALLBACK, 'is_entry_step' => true,
                'config' => [],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 2, 'escalation_action' => WrkflowSlaRule::ACTION_FAIL_STEP, 'escalation_target' => 'OPS_LEAD']);

            $base = Carbon::parse('2026-05-01 09:00:00', 'UTC');
            $this->travelTo($base);
            $instance = app(WorkflowService::class)->start('demo.wait_park', null, null, []);

            $this->assertSame(WrkflowInstance::STATUS_RUNNING, $instance->fresh()->status);

            $instanceStep = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->firstOrFail();
            $this->assertSame(WrkflowInstanceStep::STATUS_WAITING_EXTERNAL, $instanceStep->status);
            $this->assertNotNull($instanceStep->callback_token);
            $this->assertSame(64, strlen($instanceStep->callback_token));
            $this->assertTrue($instanceStep->due_at->eq($base->copy()->addHours(2)));
        });
    }

    public function test_resuming_with_the_correct_token_completes_the_step_and_advances_the_instance(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.wait_resume');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WAIT_FOR_CALLBACK, 'is_entry_step' => true, 'config' => [],
            ]);

            $instance = app(WorkflowService::class)->start('demo.wait_resume', null, null, []);
            $token = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->value('callback_token');

            $resumed = app(WorkflowService::class)->resumeFromCallback($token, ['result' => 'signed']);

            $this->assertSame(WrkflowInstanceStep::STATUS_COMPLETED, $resumed->status);
            $this->assertNotNull($resumed->callback_used_at);
            $this->assertSame(WrkflowInstance::STATUS_COMPLETED, $instance->fresh()->status);
        });
    }

    public function test_resuming_with_an_unknown_token_throws(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $this->expectException(WorkflowEngineException::class);
            app(WorkflowService::class)->resumeFromCallback('does-not-exist');
        });
    }

    public function test_a_replayed_token_is_rejected_and_the_step_advances_exactly_once(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.wait_replay');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WAIT_FOR_CALLBACK, 'is_entry_step' => true, 'config' => [],
            ]);

            $instance = app(WorkflowService::class)->start('demo.wait_replay', null, null, []);
            $token = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->value('callback_token');

            app(WorkflowService::class)->resumeFromCallback($token);

            try {
                app(WorkflowService::class)->resumeFromCallback($token);
                $this->fail('Expected a WorkflowEngineException for a replayed callback token.');
            } catch (WorkflowEngineException $e) {
                $this->assertStringContainsString('already been used', $e->getMessage());
            }

            $this->assertSame(1, WrkflowInstanceStep::query()->where('instance_id', $instance->id)->where('status', WrkflowInstanceStep::STATUS_COMPLETED)->count());
        });
    }

    public function test_a_timed_out_wait_for_callback_step_fails_via_the_sla_sweep_and_a_late_callback_is_then_rejected(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.wait_timeout');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WAIT_FOR_CALLBACK, 'is_entry_step' => true, 'config' => [],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_FAIL_STEP, 'escalation_target' => 'OPS_LEAD']);

            $base = Carbon::parse('2026-05-01 09:00:00', 'UTC');
            $this->travelTo($base);
            $instance = app(WorkflowService::class)->start('demo.wait_timeout', null, null, []);
            $token = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->value('callback_token');

            $this->travelTo($base->copy()->addHours(2));
            $this->assertSame(1, app(SlaEscalationService::class)->escalateBreachedSteps());

            $instanceStep = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->firstOrFail();
            $this->assertSame(WrkflowInstanceStep::STATUS_FAILED, $instanceStep->status);
            $this->assertSame(WrkflowInstance::STATUS_FAILED, $instance->fresh()->status);

            try {
                app(WorkflowService::class)->resumeFromCallback($token);
                $this->fail('A callback for a step that already timed out and failed must be rejected.');
            } catch (WorkflowEngineException $e) {
                $this->assertStringContainsString('no longer awaiting', $e->getMessage());
            }
        });
    }

    // ---- HTTP endpoint ---------------------------------------------------------------------

    public function test_http_callback_endpoint_resolves_tenant_from_the_url_and_resumes_the_step(): void
    {
        $tenant = $this->provisionTenant();
        $token = null;

        $tenant->run(function () use (&$token) {
            $version = $this->publishedVersion('demo.wait_http');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_WAIT_FOR_CALLBACK, 'is_entry_step' => true, 'config' => [],
            ]);
            $instance = app(WorkflowService::class)->start('demo.wait_http', null, null, []);
            $token = WrkflowInstanceStep::query()->where('instance_id', $instance->id)->value('callback_token');
        });

        // No login, no session — an external caller has neither.
        $response = $this->postJson("/api/wne/{$tenant->id}/callbacks/{$token}", ['result' => 'ok']);

        $response->assertOk();
        $response->assertJson(['status' => WrkflowInstanceStep::STATUS_COMPLETED]);

        $tenant->run(function () {
            $this->assertSame(1, WrkflowInstanceStep::query()->where('status', WrkflowInstanceStep::STATUS_COMPLETED)->count());
        });
    }

    public function test_http_callback_endpoint_returns_404_for_an_unknown_tenant(): void
    {
        $this->postJson('/api/wne/does-not-exist/callbacks/whatever')->assertNotFound();
    }

    public function test_http_callback_endpoint_returns_410_for_an_invalid_token(): void
    {
        $tenant = $this->provisionTenant();

        $this->postJson("/api/wne/{$tenant->id}/callbacks/bogus")->assertStatus(410);
    }
}
