<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\WNE\Exceptions\WorkflowEngineException;
use App\Modules\WNE\Jobs\SendNotificationDeliveryJob;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowInstance;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\WorkflowDefinitionService;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3K — Message Queue & Async Processing. The dedicated `notifications` queue
 * and the event→job fan-out (1 header + N deliveries + N queued jobs) already existed as a
 * byproduct of §3I; what's new here is wiring a `notify` step into the engine itself (§3D's
 * AUTO_ADVANCE_TYPES gains a member whose "action" is firing a message, not evaluating a
 * condition) and completing the job class's own framework-level retry/terminal-failure
 * handling. Retry *policy* and the dead letter queue are §3M's job, not this one.
 */
class WorkflowQueueAsyncProcessingTest extends TestCase
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

    public function test_a_notify_entry_step_fires_a_notification_and_the_instance_completes(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $version = $this->publishedVersion('demo.notify_direct');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true,
                'config' => ['category' => 'demo.notify_direct', 'recipient' => ['type' => 'user', 'user_id' => $admin], 'subject' => 'Direct', 'body' => 'Hi {{who}}'],
            ]);

            $instance = app(WorkflowService::class)->start('demo.notify_direct', null, null, ['who' => 'Bob']);

            $this->assertSame(WrkflowInstance::STATUS_COMPLETED, $instance->fresh()->status);

            $notification = MsgNotification::query()->where('category_code', 'demo.notify_direct')->first();
            $this->assertNotNull($notification);
            $this->assertSame($admin, $notification->recipient_user_id);
            $this->assertSame(['who' => 'Bob'], $notification->data);
            $this->assertSame(MsgNotification::STATUS_SENT, $notification->fresh()->status);
        });
    }

    public function test_a_notify_step_role_recipient_fans_out_the_same_as_a_direct_messaging_call(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $groupId = ConfigGroup::query()->firstOrCreate(['app_code' => 'NUSAEVO', 'code' => 'OPS_LEAD'], ['descr' => 'OPS_LEAD', 'status_code' => 'A'])->id;
            $userA = User::factory()->create(['email' => 'a@nusaevo.com', 'password' => 'password', 'email_verified_at' => now()]);
            $userB = User::factory()->create(['email' => 'b@nusaevo.com', 'password' => 'password', 'email_verified_at' => now()]);
            ConfigGroupUser::query()->create(['group_id' => $groupId, 'group_code' => 'OPS_LEAD', 'user_id' => $userA->id]);
            ConfigGroupUser::query()->create(['group_id' => $groupId, 'group_code' => 'OPS_LEAD', 'user_id' => $userB->id]);

            $version = $this->publishedVersion('demo.notify_role');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true,
                'config' => ['category' => 'demo.notify_role', 'recipient' => ['type' => 'role', 'role' => 'OPS_LEAD']],
            ]);

            app(WorkflowService::class)->start('demo.notify_role', null, null, []);

            $recipients = MsgNotification::query()->where('category_code', 'demo.notify_role')->pluck('recipient_user_id')->all();
            $this->assertEqualsCanonicalizing([$userA->id, $userB->id], $recipients);
        });
    }

    public function test_a_notify_step_payload_field_recipient_resolves_the_user_from_the_instance_payload(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $version = $this->publishedVersion('demo.notify_payload_field');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true,
                'config' => ['category' => 'demo.notify_payload_field', 'recipient' => ['type' => 'payload_field', 'field' => 'requester_id']],
            ]);

            app(WorkflowService::class)->start('demo.notify_payload_field', null, null, ['requester_id' => $admin]);

            $notification = MsgNotification::query()->where('category_code', 'demo.notify_payload_field')->first();
            $this->assertSame($admin, $notification->recipient_user_id);
        });
    }

    public function test_a_notify_step_payload_field_recipient_missing_from_the_payload_throws_and_rolls_back(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.notify_payload_field_missing');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true,
                'config' => ['category' => 'demo.x', 'recipient' => ['type' => 'payload_field', 'field' => 'requester_id']],
            ]);

            $this->expectException(WorkflowEngineException::class);
            $this->expectExceptionMessage('resolved to no value');

            try {
                app(WorkflowService::class)->start('demo.notify_payload_field_missing', null, null, []);
            } finally {
                $this->assertSame(0, WrkflowInstance::query()->count(), 'a misconfigured notify step must roll back the whole start(), not leave a half-started instance');
            }
        });
    }

    public function test_a_notify_step_with_no_recipient_configured_throws(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.notify_no_recipient');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true,
                'config' => ['category' => 'demo.x'],
            ]);

            $this->expectException(WorkflowEngineException::class);
            $this->expectExceptionMessage('no recipient configured');

            app(WorkflowService::class)->start('demo.notify_no_recipient', null, null, []);
        });
    }

    public function test_publishing_a_notify_step_with_no_recipient_is_blocked_at_publish_time(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $service = app(WorkflowDefinitionService::class);
            $definition = $service->create(['code' => 'demo.notify_publish_gate', 'name' => 'Gate'], $this->adminId());
            $service->addStep($definition, ['step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true, 'config' => ['category' => 'demo.x']]);

            try {
                $service->publish($definition, $this->adminId());
                $this->fail('Expected a ValidationException for the notify step missing a recipient.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('entry', $e->validator->errors()->first('steps'));
            }

            $this->assertSame(WrkflowDefinition::STATUS_DRAFT, $definition->fresh()->status);
        });
    }

    /**
     * A notify step's own job is "attempt the send" — an unresolvable recipient (no HCM
     * org-chart, a role with zero members, ...) is MessagingService's problem to record on
     * the msg_notifications header, never a reason to fail the workflow step itself.
     */
    public function test_a_notify_step_with_an_unresolvable_recipient_still_completes_the_workflow(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $version = $this->publishedVersion('demo.notify_unresolvable');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true,
                'config' => ['category' => 'demo.notify_unresolvable', 'recipient' => ['type' => 'role', 'role' => 'NOBODY_HERE']],
            ]);

            $instance = app(WorkflowService::class)->start('demo.notify_unresolvable', null, null, []);

            $this->assertSame(WrkflowInstance::STATUS_COMPLETED, $instance->fresh()->status);
            $notification = MsgNotification::query()->where('category_code', 'demo.notify_unresolvable')->first();
            $this->assertSame(MsgNotification::STATUS_FAILED, $notification->status);
        });
    }

    public function test_delivery_jobs_dispatch_onto_the_dedicated_notifications_queue(): void
    {
        Queue::fake();
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $version = $this->publishedVersion('demo.notify_queue_name');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'entry', 'type' => WrkflowStep::TYPE_NOTIFY, 'is_entry_step' => true,
                'config' => ['category' => 'demo.notify_queue_name', 'recipient' => ['type' => 'user', 'user_id' => $admin]],
            ]);

            app(WorkflowService::class)->start('demo.notify_queue_name', null, null, []);

            Queue::assertPushedOn('notifications', SendNotificationDeliveryJob::class);
        });
    }

    public function test_job_has_a_bounded_framework_level_retry_policy(): void
    {
        $job = new SendNotificationDeliveryJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
    }

    public function test_job_failed_hook_marks_a_still_pending_delivery_failed_and_recomputes_the_header(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $notification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_PENDING, 'created_at' => now(),
            ]);
            $delivery = MsgNotificationDelivery::query()->create([
                'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => MsgNotificationDelivery::STATUS_PENDING,
            ]);

            (new SendNotificationDeliveryJob($delivery->id))->failed(new \RuntimeException('DB blip'));

            $delivery->refresh();
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->status);
            $this->assertSame('DB blip', $delivery->error);
            $this->assertSame(MsgNotification::STATUS_FAILED, $notification->fresh()->status);
        });
    }

    public function test_job_failed_hook_does_not_overwrite_a_delivery_the_driver_already_resolved(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $notification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_SENT, 'created_at' => now(),
            ]);
            $delivery = MsgNotificationDelivery::query()->create([
                'notification_id' => $notification->id, 'channel' => 'in_app',
                'status' => MsgNotificationDelivery::STATUS_SENT, 'sent_at' => now(),
            ]);

            (new SendNotificationDeliveryJob($delivery->id))->failed(new \RuntimeException('should be ignored'));

            $this->assertSame(MsgNotificationDelivery::STATUS_SENT, $delivery->fresh()->status);
        });
    }
}
