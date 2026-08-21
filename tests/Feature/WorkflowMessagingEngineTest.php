<?php

namespace Tests\Feature;

use App\Models\TenantUserLookup;
use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\WNE\Events\NotificationRequested;
use App\Modules\WNE\Mail\GenericNotificationMail;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowSlaRule;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\MessagingService;
use App\Modules\WNE\Services\SlaEscalationService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3I — Multi-Channel Delivery Engine. Covers recipient resolution (direct /
 * role fan-out / unresolvable), the "any channel succeeds ⇒ header sent" aggregate rule,
 * the credentials-gated Sms/Push drivers, and the NotificationRequested → MessagingService
 * integration built in §3F but only wired up now.
 */
class WorkflowMessagingEngineTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    private function createGroupUser(string $email, string $groupCode): int
    {
        $user = User::factory()->create(['email' => $email, 'password' => 'password', 'email_verified_at' => now()]);
        $groupId = ConfigGroup::query()->firstOrCreate(['app_code' => 'NUSAEVO', 'code' => $groupCode], ['descr' => $groupCode, 'status_code' => 'A'])->id;
        ConfigGroupUser::query()->create(['group_id' => $groupId, 'group_code' => $groupCode, 'user_id' => $user->id]);
        TenantUserLookup::query()->updateOrCreate(['email' => $email, 'tenant_id' => '001'], []);

        return $user->id;
    }

    public function test_direct_user_recipient_defaults_to_in_app_and_is_marked_sent(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();

            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
            );

            $this->assertCount(1, $headers);
            $header = $headers->first()->fresh();
            $this->assertSame($admin, $header->recipient_user_id);
            $this->assertSame(MsgNotification::STATUS_SENT, $header->status);

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame('in_app', $delivery->channel);
            $this->assertSame(MsgNotificationDelivery::STATUS_SENT, $delivery->status);
        });
    }

    public function test_role_recipient_fans_out_to_one_header_per_member(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $userA = $this->createGroupUser('a@nusaevo.com', 'OPS_LEAD');
            $userB = $this->createGroupUser('b@nusaevo.com', 'OPS_LEAD');

            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'role', 'role' => 'OPS_LEAD'],
                subject: 'Hello',
                body: 'World',
            );

            $this->assertCount(2, $headers);
            $this->assertEqualsCanonicalizing([$userA, $userB], $headers->pluck('recipient_user_id')->all());
        });
    }

    public function test_role_recipient_with_no_members_creates_a_failed_header_not_a_silent_drop(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'role', 'role' => 'NOBODY_HERE'],
                subject: 'Hello',
                body: 'World',
            );

            $this->assertCount(1, $headers);
            $header = $headers->first();
            $this->assertNull($header->recipient_user_id);
            $this->assertSame(MsgNotification::STATUS_FAILED, $header->status);
            $this->assertStringContainsString('NOBODY_HERE', $header->error);
            $this->assertSame(0, MsgNotificationDelivery::query()->where('notification_id', $header->id)->count());
        });
    }

    public function test_manager_of_user_recipient_is_recorded_as_unresolved(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();

            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'manager_of_user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
            );

            $header = $headers->first();
            $this->assertSame(MsgNotification::STATUS_FAILED, $header->status);
            $this->assertNotNull($header->error);
            $this->assertSame(0, MsgNotificationDelivery::query()->where('notification_id', $header->id)->count());
        });
    }

    public function test_header_status_is_sent_when_any_channel_succeeds_even_if_another_fails(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();

            // No sms channel config exists — that delivery will fail; in_app always succeeds.
            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                channels: ['in_app', 'sms'],
            );

            $header = $headers->first()->fresh();
            $this->assertSame(MsgNotification::STATUS_SENT, $header->status);

            $statuses = MsgNotificationDelivery::query()->where('notification_id', $header->id)->pluck('status', 'channel');
            $this->assertSame(MsgNotificationDelivery::STATUS_SENT, $statuses['in_app']);
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $statuses['sms']);
        });
    }

    public function test_email_driver_sends_via_mail_facade(): void
    {
        Mail::fake();
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();

            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                channels: ['email'],
            );

            $header = $headers->first()->fresh();
            $this->assertSame(MsgNotification::STATUS_SENT, $header->status);
            Mail::assertSent(GenericNotificationMail::class, fn ($mail) => $mail->hasTo(User::find($admin)->email));
        });
    }

    public function test_sms_driver_calls_twilio_when_configured(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS,
                'enabled' => true,
                'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'secret'],
                'config' => ['from_number' => '+10000000000'],
            ]);

            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                data: ['phone' => '+19998887777'],
                channels: ['sms'],
            );

            $header = $headers->first()->fresh();
            $this->assertSame(MsgNotification::STATUS_SENT, $header->status);
            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame('SM123', $delivery->provider_message_id);

            Http::assertSent(fn ($request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
                && $request['To'] === '+19998887777'
                && $request['From'] === '+10000000000');
        });
    }

    public function test_sms_driver_fails_cleanly_when_not_configured(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();

            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                channels: ['sms'],
            );

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $headers->first()->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->status);
            $this->assertStringContainsString('not configured', $delivery->error);
        });
    }

    public function test_push_driver_always_reports_not_implemented_even_when_configured(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgChannelConfig::query()->create(['channel' => MsgChannelConfig::CHANNEL_PUSH, 'enabled' => true, 'credentials' => ['server_key' => 'x'], 'config' => []]);

            $headers = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                channels: ['push'],
            );

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $headers->first()->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->status);
            $this->assertStringContainsString('not yet implemented', $delivery->error);
        });
    }

    /** Structural check that AppServiceProvider actually wired the listener — not just that MessagingService works in isolation. */
    public function test_notification_requested_event_is_registered_to_the_listener(): void
    {
        $this->assertTrue(Event::hasListeners(NotificationRequested::class));
    }

    /**
     * End-to-end: §3F's SLA escalation sweep fires NotificationRequested from inside its own
     * DB::transaction() — the listener (queued + afterCommit) must still turn that into a real
     * msg_notifications row once this test's real DB commit happens, proving the seam §3F
     * deliberately left open is now actually closed.
     */
    public function test_an_sla_breach_escalation_is_delivered_through_the_real_listener(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $definition = WrkflowDefinition::query()->create(['code' => 'demo.msg_integration', 'name' => 'Msg Integration', 'status' => WrkflowDefinition::STATUS_PUBLISHED]);
            $version = WrkflowVersion::query()->create(['definition_id' => $definition->id, 'version_no' => 1, 'published_by' => $this->adminId()]);
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $this->adminId()]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $entry->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'OPS_LEAD']);
            $this->createGroupUser('ops@nusaevo.com', 'OPS_LEAD');

            $base = Carbon::parse('2026-03-01 08:00:00');
            $this->travelTo($base);
            app(WorkflowService::class)->start('demo.msg_integration', null, null, []);

            $this->travelTo($base->copy()->addHours(2));
            $this->assertSame(1, app(SlaEscalationService::class)->escalateBreachedSteps());

            $notification = MsgNotification::query()->where('category_code', 'wne.sla_breach')->first();
            $this->assertNotNull($notification, 'the listener should have created a msg_notifications row for the SLA breach');
            $this->assertStringContainsString("step 'entry'", $notification->subject);
            $this->assertSame(MsgNotification::STATUS_SENT, $notification->fresh()->status);
        });
    }
}
