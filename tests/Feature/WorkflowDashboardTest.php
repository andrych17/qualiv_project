<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Models\MsgDeadLetter;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Models\WrkflowDefinition;
use App\Modules\WNE\Models\WrkflowSlaRule;
use App\Modules\WNE\Models\WrkflowStep;
use App\Modules\WNE\Models\WrkflowVersion;
use App\Modules\WNE\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3A — Main Dashboard. Summary cards + the tabbed table (My Tasks | Active
 * Instances | SLA Breaches | DLQ / Failed Deliveries) aggregating §3C-§3O — ships last, per
 * the suggested build order, once every engine it summarizes already exists.
 */
class WorkflowDashboardTest extends TestCase
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

    public function test_root_wne_redirects_to_the_dashboard(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne')->assertRedirect('/wne/dashboard');
    }

    public function test_dashboard_renders_and_reports_correct_summary_counts(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = $this->adminId();

            // An active instance with no breach — should count toward active_instances only.
            $version = $this->publishedVersion('demo.healthy');
            $entry = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'approve', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin]],
            ]);
            app(WorkflowService::class)->start('demo.healthy', null, null, []);

            // A breached instance step — counts toward sla_breaches_24h and the my_pending_tasks card (assigned to admin).
            $breachedVersion = $this->publishedVersion('demo.breached');
            $breachedStep = WrkflowStep::query()->create([
                'version_id' => $breachedVersion->id, 'step_code' => 'approve', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $breachedStep->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'OPS']);
            $this->travelTo(Carbon::parse('2026-05-01 09:00:00', 'UTC'));
            app(WorkflowService::class)->start('demo.breached', null, null, []);
            $this->travelTo(Carbon::parse('2026-05-01 12:00:00', 'UTC')); // 2h later — past the 1h SLA

            // Notifications today: one sent, one failed.
            $notification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_SENT, 'created_at' => now(),
            ]);
            MsgNotificationDelivery::query()->create(['notification_id' => $notification->id, 'channel' => 'in_app', 'status' => MsgNotificationDelivery::STATUS_SENT]);
            $failedNotification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_FAILED, 'created_at' => now(),
            ]);
            MsgNotificationDelivery::query()->create(['notification_id' => $failedNotification->id, 'channel' => 'sms', 'status' => MsgNotificationDelivery::STATUS_FAILED]);

            // One open dead letter.
            $dlqNotification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'DLQ item', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_FAILED, 'created_at' => now(),
            ]);
            $dlqDelivery = MsgNotificationDelivery::query()->create(['notification_id' => $dlqNotification->id, 'channel' => 'sms', 'status' => MsgNotificationDelivery::STATUS_DEAD_LETTERED]);
            MsgDeadLetter::query()->create([
                'delivery_id' => $dlqDelivery->id, 'notification_id' => $dlqNotification->id, 'channel' => 'sms',
                'recipient_user_id' => $admin, 'subject' => 'DLQ item', 'body' => 'Hi', 'data' => [],
                'failure_history' => [['attempt' => 1, 'error' => 'boom', 'occurred_at' => now()->toIso8601String()]],
                'created_at' => now(),
            ]);
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $response = $this->get('/wne/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('WNE/Dashboard/Index')
            ->where('summary.active_instances', 2)
            ->where('summary.my_pending_tasks', 2)
            ->where('summary.sla_breaches_24h', 1)
            ->where('summary.notifications_sent_today', 1)
            // 1 sent + 2 failed (the plain failure + the DLQ item's own notification) = 3 total.
            ->where('summary.notifications_failure_rate_today', 67)
            ->where('summary.dlq_items', 1)
        );
    }

    public function test_active_instances_tab_flags_a_breached_instance_with_the_danger_rail(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = $this->adminId();
            $version = $this->publishedVersion('demo.rail');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'approve', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $step->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'OPS']);
            $this->travelTo(Carbon::parse('2026-05-01 09:00:00', 'UTC'));
            app(WorkflowService::class)->start('demo.rail', null, null, []);
            $this->travelTo(Carbon::parse('2026-05-01 12:00:00', 'UTC'));
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/dashboard')->assertInertia(fn ($page) => $page
            ->component('WNE/Dashboard/Index')
            ->where('activeInstances.0.rail', 'danger')
            ->where('activeInstances.0.workflow_name', 'demo.rail')
        );
    }

    public function test_sla_breaches_tab_lists_a_currently_breached_step(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = $this->adminId();
            $version = $this->publishedVersion('demo.breach_tab');
            $step = WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'approve', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin]],
            ]);
            WrkflowSlaRule::query()->create(['step_id' => $step->id, 'sla_hours' => 1, 'escalation_action' => WrkflowSlaRule::ACTION_NOTIFY_ROLE, 'escalation_target' => 'OPS']);
            $this->travelTo(Carbon::parse('2026-05-01 09:00:00', 'UTC'));
            app(WorkflowService::class)->start('demo.breach_tab', null, null, []);
            $this->travelTo(Carbon::parse('2026-05-01 12:00:00', 'UTC'));
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/dashboard')->assertInertia(fn ($page) => $page
            ->component('WNE/Dashboard/Index')
            ->has('slaBreaches', 1)
            ->where('slaBreaches.0.step_code', 'approve')
        );
    }

    public function test_dlq_tab_lists_only_unactioned_dead_letters(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = $this->adminId();

            $open = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Open DLQ', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_FAILED, 'created_at' => now(),
            ]);
            $openDelivery = MsgNotificationDelivery::query()->create(['notification_id' => $open->id, 'channel' => 'sms', 'status' => MsgNotificationDelivery::STATUS_DEAD_LETTERED]);
            MsgDeadLetter::query()->create([
                'delivery_id' => $openDelivery->id, 'notification_id' => $open->id, 'channel' => 'sms',
                'recipient_user_id' => $admin, 'subject' => 'Open DLQ', 'body' => 'Hi', 'data' => [],
                'failure_history' => [], 'created_at' => now(),
            ]);

            $resolved = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Resolved DLQ', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_FAILED, 'created_at' => now(),
            ]);
            $resolvedDelivery = MsgNotificationDelivery::query()->create(['notification_id' => $resolved->id, 'channel' => 'sms', 'status' => MsgNotificationDelivery::STATUS_DEAD_LETTERED]);
            MsgDeadLetter::query()->create([
                'delivery_id' => $resolvedDelivery->id, 'notification_id' => $resolved->id, 'channel' => 'sms',
                'recipient_user_id' => $admin, 'subject' => 'Resolved DLQ', 'body' => 'Hi', 'data' => [],
                'failure_history' => [], 'created_at' => now(), 'discarded_at' => now(), 'discarded_by' => $admin,
            ]);
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/dashboard')->assertInertia(fn ($page) => $page
            ->component('WNE/Dashboard/Index')
            ->has('dlqItems', 1)
            ->where('dlqItems.0.subject', 'Open DLQ')
            ->where('summary.dlq_items', 1)
        );
    }

    public function test_my_tasks_tab_only_shows_tasks_assigned_to_the_current_user(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = $this->adminId();
            $otherUser = User::factory()->create(['email' => 'someone-else@nusaevo.com', 'password' => 'password']);

            $version = $this->publishedVersion('demo.mine');
            WrkflowStep::query()->create([
                'version_id' => $version->id, 'step_code' => 'approve', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $admin]],
            ]);
            app(WorkflowService::class)->start('demo.mine', null, null, []);

            $otherVersion = $this->publishedVersion('demo.not_mine');
            WrkflowStep::query()->create([
                'version_id' => $otherVersion->id, 'step_code' => 'approve', 'type' => WrkflowStep::TYPE_APPROVAL, 'is_entry_step' => true,
                'config' => ['assignee' => ['type' => 'user', 'user_id' => $otherUser->id]],
            ]);
            app(WorkflowService::class)->start('demo.not_mine', null, null, []);
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/dashboard')->assertInertia(fn ($page) => $page
            ->component('WNE/Dashboard/Index')
            ->has('myTasks', 1)
            ->where('myTasks.0.workflow_name', 'demo.mine')
        );
    }
}
