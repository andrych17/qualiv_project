<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Jobs\SendNotificationDeliveryJob;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgDeadLetter;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Services\MessagingService;
use App\Modules\WNE\Services\RetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3M — Retry Mechanism & Dead Letter Queue. Retry is opt-in per channel
 * (`max_attempts > 1` explicitly configured on `msg_channel_configs`) — every §3I/§3L/§3J
 * test that never configures a retry policy must keep behaving exactly as before this
 * section: one failed attempt, plain `failed`, no dead letter.
 */
class WorkflowRetryDeadLetterQueueTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    public function test_a_failure_with_no_channel_policy_fails_immediately_with_no_dead_letter(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();

            $header = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                channels: ['sms'],
            )->first();

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->status);
            $this->assertSame(1, $delivery->attempt);
            $this->assertSame(0, MsgDeadLetter::query()->count());
        });
    }

    public function test_a_channel_with_max_attempts_one_also_fails_immediately(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true,
                'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'secret'], 'config' => ['from_number' => '+10000000000'],
                'max_attempts' => 1,
            ]);
            Http::fake(['api.twilio.com/*' => Http::response(['message' => 'boom'], 500)]);

            $header = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                data: ['phone' => '+19998887777'],
                channels: ['sms'],
            )->first();

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->status);
            $this->assertSame(0, MsgDeadLetter::query()->count());
            Http::assertSentCount(1);
        });
    }

    public function test_a_multi_attempt_policy_retries_then_dead_letters_after_exhausting_attempts(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true,
                'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'secret'], 'config' => ['from_number' => '+10000000000'],
                'max_attempts' => 3, 'backoff_schedule' => [1, 2],
            ]);
            Http::fake(['api.twilio.com/*' => Http::response(['message' => 'Twilio is down'], 500)]);

            $header = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $admin],
                subject: 'Hello',
                body: 'World',
                data: ['phone' => '+19998887777'],
                channels: ['sms'],
            )->first();

            // QUEUE_CONNECTION=sync in this dev/test environment executes every dispatched
            // job immediately regardless of ->delay(), so the whole retry chain cascades
            // synchronously within this one notify() call.
            Http::assertSentCount(3);

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_DEAD_LETTERED, $delivery->status);
            $this->assertCount(3, $delivery->retry_history);
            $this->assertSame(1, $delivery->retry_history[0]['attempt']);
            $this->assertSame(3, $delivery->retry_history[2]['attempt']);

            $deadLetter = MsgDeadLetter::query()->where('delivery_id', $delivery->id)->firstOrFail();
            $this->assertSame('sms', $deadLetter->channel);
            $this->assertSame($admin, $deadLetter->recipient_user_id);
            $this->assertCount(3, $deadLetter->failure_history);
            $this->assertStringContainsString('Twilio', $deadLetter->failure_history[2]['error']);

            $this->assertSame(MsgNotification::STATUS_FAILED, $header->fresh()->status);
        });
    }

    public function test_backoff_uses_the_configured_schedule_when_one_is_set(): void
    {
        $service = app(RetryService::class);

        $this->assertSame(111, $service->backoffSeconds(1, [111, 222]));
        $this->assertSame(222, $service->backoffSeconds(2, [111, 222]));
        // Past the end of an explicit schedule, hold at its last value rather than indexing past it.
        $this->assertSame(222, $service->backoffSeconds(3, [111, 222]));
    }

    public function test_default_exponential_backoff_is_used_when_no_schedule_is_configured(): void
    {
        $service = app(RetryService::class);

        // Spec's own example schedule: 1 min → 5 min → 30 min → 2 hr.
        $this->assertSame(60, $service->backoffSeconds(1, null));
        $this->assertSame(300, $service->backoffSeconds(2, null));
        $this->assertSame(1800, $service->backoffSeconds(3, null));
        $this->assertSame(7200, $service->backoffSeconds(4, null));
    }

    public function test_a_multi_attempt_policy_dispatches_a_delayed_retry_job(): void
    {
        Queue::fake();
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true, 'max_attempts' => 3, 'backoff_schedule' => [111, 222],
            ]);

            $notification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_PENDING, 'created_at' => now(),
            ]);
            $delivery = MsgNotificationDelivery::query()->create(['notification_id' => $notification->id, 'channel' => 'sms', 'status' => MsgNotificationDelivery::STATUS_PENDING, 'attempt' => 1]);

            app(RetryService::class)->handleFailure($delivery, 'transient error');

            $this->assertSame(MsgNotificationDelivery::STATUS_RETRYING, $delivery->fresh()->status);
            $this->assertSame(2, $delivery->fresh()->attempt);
            $this->assertCount(1, $delivery->fresh()->retry_history);
            Queue::assertPushed(SendNotificationDeliveryJob::class, fn ($job) => $job->deliveryId === $delivery->id);
        });
    }

    public function test_resend_resets_the_attempt_counter_and_marks_the_dead_letter(): void
    {
        Queue::fake();
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $deadLetter = $this->makeDeadLetter($admin);

            app(RetryService::class)->resend($deadLetter, $admin);

            $this->assertNotNull($deadLetter->fresh()->resent_at);
            $this->assertSame($admin, $deadLetter->fresh()->resent_by);

            $delivery = $deadLetter->delivery()->first();
            $this->assertSame(MsgNotificationDelivery::STATUS_PENDING, $delivery->status);
            $this->assertSame(1, $delivery->attempt);
            $this->assertNull($delivery->error);

            Queue::assertPushed(SendNotificationDeliveryJob::class, 1);
        });
    }

    public function test_resend_is_a_noop_on_a_second_click(): void
    {
        Queue::fake();
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $deadLetter = $this->makeDeadLetter($admin);

            app(RetryService::class)->resend($deadLetter, $admin);
            $firstResentAt = $deadLetter->fresh()->resent_at;

            app(RetryService::class)->resend($deadLetter, $admin);

            $this->assertTrue($firstResentAt->eq($deadLetter->fresh()->resent_at));
            Queue::assertPushed(SendNotificationDeliveryJob::class, 1);
        });
    }

    public function test_discard_marks_the_dead_letter_without_touching_the_delivery(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $deadLetter = $this->makeDeadLetter($admin);

            app(RetryService::class)->discard($deadLetter, $admin);

            $this->assertNotNull($deadLetter->fresh()->discarded_at);
            $this->assertSame($admin, $deadLetter->fresh()->discarded_by);
            $this->assertSame(MsgNotificationDelivery::STATUS_DEAD_LETTERED, $deadLetter->delivery()->first()->status);
        });
    }

    public function test_discard_after_resend_is_a_noop(): void
    {
        Queue::fake();
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $deadLetter = $this->makeDeadLetter($admin);

            app(RetryService::class)->resend($deadLetter, $admin);
            app(RetryService::class)->discard($deadLetter, $admin);

            $this->assertNull($deadLetter->fresh()->discarded_at);
        });
    }

    public function test_a_stale_job_does_not_reprocess_an_already_terminal_delivery(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $notification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_FAILED, 'created_at' => now(),
            ]);
            $delivery = MsgNotificationDelivery::query()->create([
                'notification_id' => $notification->id, 'channel' => 'in_app', 'status' => MsgNotificationDelivery::STATUS_FAILED, 'error' => 'original error',
            ]);

            (new SendNotificationDeliveryJob($delivery->id))->handle();

            // If the terminal-status guard were missing, in_app always succeeds and would
            // have flipped this to `sent` with a `sent_at` timestamp.
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->fresh()->status);
            $this->assertSame('original error', $delivery->fresh()->error);
            $this->assertNull($delivery->fresh()->sent_at);
        });
    }

    public function test_failed_hook_resolves_a_stuck_retrying_delivery_not_just_pending(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $admin = $this->adminId();
            $notification = MsgNotification::query()->create([
                'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $admin,
                'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_PENDING, 'created_at' => now(),
            ]);
            $delivery = MsgNotificationDelivery::query()->create([
                'notification_id' => $notification->id, 'channel' => 'sms', 'status' => MsgNotificationDelivery::STATUS_RETRYING, 'attempt' => 2,
            ]);

            (new SendNotificationDeliveryJob($delivery->id))->failed(new \RuntimeException('DB blip on the retry attempt'));

            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->fresh()->status);
            $this->assertSame('DB blip on the retry attempt', $delivery->fresh()->error);
        });
    }

    public function test_http_resend_and_discard_endpoints(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'legal']);
        $admin = null;
        $deadLetterId = null;
        $secondDeadLetterId = null;

        $tenant->run(function () use (&$admin, &$deadLetterId, &$secondDeadLetterId) {
            $admin = $this->adminId();
            $deadLetterId = $this->makeDeadLetter($admin)->id;
            $secondDeadLetterId = $this->makeDeadLetter($admin)->id;
        });

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/wne/dead-letters')->assertOk();

        $this->post("/wne/dead-letters/{$deadLetterId}/resend")->assertRedirect();
        $this->post("/wne/dead-letters/{$secondDeadLetterId}/discard")->assertRedirect();

        $tenant->run(function () use ($deadLetterId, $secondDeadLetterId) {
            $this->assertNotNull(MsgDeadLetter::query()->find($deadLetterId)->resent_at);
            $this->assertNotNull(MsgDeadLetter::query()->find($secondDeadLetterId)->discarded_at);
        });
    }

    private function makeDeadLetter(int $userId): MsgDeadLetter
    {
        $notification = MsgNotification::query()->create([
            'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $userId,
            'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_FAILED, 'created_at' => now(),
        ]);
        $delivery = MsgNotificationDelivery::query()->create([
            'notification_id' => $notification->id, 'channel' => 'sms',
            'status' => MsgNotificationDelivery::STATUS_DEAD_LETTERED, 'attempt' => 3, 'error' => 'boom',
            'retry_history' => [['attempt' => 1, 'error' => 'boom', 'occurred_at' => now()->toIso8601String()]],
        ]);

        return MsgDeadLetter::query()->create([
            'delivery_id' => $delivery->id, 'notification_id' => $notification->id, 'channel' => 'sms',
            'recipient_user_id' => $userId, 'subject' => 'Hi', 'body' => 'Hi', 'data' => [],
            'failure_history' => [['attempt' => 1, 'error' => 'boom', 'occurred_at' => now()->toIso8601String()]],
            'created_at' => now(),
        ]);
    }
}
