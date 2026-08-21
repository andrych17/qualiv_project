<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgDeliveryEvent;
use App\Modules\WNE\Models\MsgNotification;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Services\MessagingService;
use App\Modules\WNE\Services\ObservabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * WNE_SPECS.md §3O — Observability & Tracking Engine. `msg_delivery_events` is the
 * append-only lifecycle log every other §3 section's send path writes into as it goes;
 * provider status webhooks (SendGrid/Twilio) ingest into the same log opportunistically,
 * gated by MsgNotificationDelivery::canAdvanceStatusTo() so an unordered/duplicate callback
 * can never drag a delivery's own status backward or past a status our own pipeline settled.
 */
class WorkflowObservabilityTrackingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function adminId(): int
    {
        return User::query()->where('email', 'admin@nusaevo.com')->value('id');
    }

    // ---- Internal pipeline logging --------------------------------------------------------

    public function test_a_successful_in_app_send_logs_created_queued_sending_and_sent_events_in_order(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $header = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $this->adminId()],
                subject: 'Hello',
                body: 'World',
                channels: ['in_app'],
            )->first();

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_SENT, $delivery->status);

            $events = $delivery->events()->pluck('event_type')->all();
            $this->assertSame([
                MsgDeliveryEvent::EVENT_CREATED,
                MsgDeliveryEvent::EVENT_QUEUED,
                MsgDeliveryEvent::EVENT_SENDING,
                MsgDeliveryEvent::EVENT_SENT,
            ], $events);
        });
    }

    public function test_an_immediate_failure_with_no_retry_policy_logs_a_failed_event(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $header = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $this->adminId()],
                subject: 'Hello',
                body: 'World',
                channels: ['sms'], // no msg_channel_configs row — SmsDriver fails cleanly, no retry policy
            )->first();

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_FAILED, $delivery->status);

            $lastEvent = $delivery->events()->get()->last();
            $this->assertSame(MsgDeliveryEvent::EVENT_FAILED, $lastEvent->event_type);
        });
    }

    public function test_a_multi_attempt_policy_logs_retrying_then_dead_lettered(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true,
                'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'secret'], 'config' => ['from_number' => '+10000000000'],
                'max_attempts' => 2, 'backoff_schedule' => [1],
            ]);
            Http::fake(['api.twilio.com/*' => Http::response(['message' => 'down'], 500)]);

            $header = app(MessagingService::class)->notify(
                category: 'demo.test',
                recipient: ['type' => 'user', 'user_id' => $this->adminId()],
                subject: 'Hello',
                body: 'World',
                data: ['phone' => '+19998887777'],
                channels: ['sms'],
            )->first();

            $delivery = MsgNotificationDelivery::query()->where('notification_id', $header->id)->firstOrFail();
            $this->assertSame(MsgNotificationDelivery::STATUS_DEAD_LETTERED, $delivery->status);

            $eventTypes = $delivery->events()->pluck('event_type')->all();
            $this->assertContains(MsgDeliveryEvent::EVENT_RETRYING, $eventTypes);
            $this->assertSame(MsgDeliveryEvent::EVENT_DEAD_LETTERED, end($eventTypes));
        });
    }

    // ---- Monotonic status guard -------------------------------------------------------------

    public function test_a_late_sent_event_cannot_downgrade_an_already_delivered_status(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $delivery = $this->makeDelivery(MsgNotificationDelivery::STATUS_DELIVERED);

            app(ObservabilityService::class)->ingestProviderEvent($delivery, MsgDeliveryEvent::EVENT_SENT, MsgNotificationDelivery::STATUS_SENT, ['late' => true]);

            $this->assertSame(MsgNotificationDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
            // The event is still recorded — append-only, even when it doesn't move the status.
            $this->assertTrue($delivery->events()->where('event_type', MsgDeliveryEvent::EVENT_SENT)->exists());
        });
    }

    public function test_a_provider_event_can_never_overwrite_a_pipeline_owned_terminal_status(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $delivery = $this->makeDelivery(MsgNotificationDelivery::STATUS_DEAD_LETTERED);

            app(ObservabilityService::class)->ingestProviderEvent($delivery, MsgDeliveryEvent::EVENT_DELIVERED, MsgNotificationDelivery::STATUS_DELIVERED, []);

            $this->assertSame(MsgNotificationDelivery::STATUS_DEAD_LETTERED, $delivery->fresh()->status);
        });
    }

    public function test_a_delivered_event_advances_status_and_sets_delivered_at(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $delivery = $this->makeDelivery(MsgNotificationDelivery::STATUS_SENT);

            app(ObservabilityService::class)->ingestProviderEvent($delivery, MsgDeliveryEvent::EVENT_DELIVERED, MsgNotificationDelivery::STATUS_DELIVERED, []);

            $this->assertSame(MsgNotificationDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
            $this->assertNotNull($delivery->fresh()->delivered_at);
        });
    }

    public function test_an_event_only_type_like_opened_never_touches_delivery_status(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $delivery = $this->makeDelivery(MsgNotificationDelivery::STATUS_SENT);

            app(ObservabilityService::class)->ingestProviderEvent($delivery, MsgDeliveryEvent::EVENT_OPENED, null, []);

            $this->assertSame(MsgNotificationDelivery::STATUS_SENT, $delivery->fresh()->status);
            $this->assertTrue($delivery->events()->where('event_type', MsgDeliveryEvent::EVENT_OPENED)->exists());
        });
    }

    // ---- SendGrid webhook ---------------------------------------------------------------------

    public function test_sendgrid_webhook_ingests_a_delivered_event_matched_by_smtp_id(): void
    {
        $tenant = $this->provisionTenant();
        $deliveryId = null;

        $tenant->run(function () use (&$deliveryId) {
            $deliveryId = $this->makeDelivery(MsgNotificationDelivery::STATUS_SENT, 'email', 'msg-abc-123')->id;
        });

        $response = $this->postJson("/api/wne/{$tenant->id}/webhooks/sendgrid", [
            ['event' => 'delivered', 'smtp-id' => '<msg-abc-123>', 'timestamp' => 1700000000],
        ]);

        $response->assertOk();

        $tenant->run(function () use ($deliveryId) {
            $delivery = MsgNotificationDelivery::query()->find($deliveryId);
            $this->assertSame(MsgNotificationDelivery::STATUS_DELIVERED, $delivery->status);
            $this->assertTrue($delivery->delivered_at->eq(Carbon::createFromTimestamp(1700000000)));
        });
    }

    public function test_sendgrid_webhook_ingests_a_bounce_and_an_open_event_from_one_batch(): void
    {
        $tenant = $this->provisionTenant();
        $deliveryId = null;
        $otherDeliveryId = null;

        $tenant->run(function () use (&$deliveryId, &$otherDeliveryId) {
            $deliveryId = $this->makeDelivery(MsgNotificationDelivery::STATUS_SENT, 'email', 'bounced-one')->id;
            $otherDeliveryId = $this->makeDelivery(MsgNotificationDelivery::STATUS_SENT, 'email', 'opened-one')->id;
        });

        $this->postJson("/api/wne/{$tenant->id}/webhooks/sendgrid", [
            ['event' => 'bounce', 'smtp-id' => '<bounced-one>', 'reason' => 'mailbox full'],
            ['event' => 'open', 'smtp-id' => '<opened-one>'],
            ['event' => 'processed', 'smtp-id' => '<opened-one>'], // outside §3O's vocabulary — must be skipped, not error
        ])->assertOk();

        $tenant->run(function () use ($deliveryId, $otherDeliveryId) {
            $bounced = MsgNotificationDelivery::query()->find($deliveryId);
            $this->assertSame(MsgNotificationDelivery::STATUS_BOUNCED, $bounced->status);

            $opened = MsgNotificationDelivery::query()->find($otherDeliveryId);
            $this->assertSame(MsgNotificationDelivery::STATUS_SENT, $opened->status); // 'open' never changes delivery status
            $this->assertTrue($opened->events()->where('event_type', MsgDeliveryEvent::EVENT_OPENED)->exists());
            $this->assertSame(1, MsgDeliveryEvent::query()->where('delivery_id', $otherDeliveryId)->count()); // 'processed' produced no row
        });
    }

    public function test_sendgrid_webhook_with_no_matching_delivery_is_a_silent_noop(): void
    {
        $tenant = $this->provisionTenant();

        $response = $this->postJson("/api/wne/{$tenant->id}/webhooks/sendgrid", [
            ['event' => 'delivered', 'smtp-id' => '<does-not-exist>'],
        ]);

        $response->assertOk();

        $tenant->run(function () {
            $this->assertSame(0, MsgDeliveryEvent::query()->count());
        });
    }

    // ---- Twilio webhook -----------------------------------------------------------------------

    public function test_twilio_webhook_rejects_a_request_with_no_configured_credentials(): void
    {
        $tenant = $this->provisionTenant();

        $this->post("/api/wne/{$tenant->id}/webhooks/twilio", ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'])
            ->assertStatus(403);
    }

    public function test_twilio_webhook_rejects_an_invalid_signature(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true,
                'credentials' => ['account_sid' => 'AC123', 'auth_token' => 'secret'], 'config' => ['from_number' => '+10000000000'],
            ]);
        });

        $this->post("/api/wne/{$tenant->id}/webhooks/twilio", ['MessageSid' => 'SM123', 'MessageStatus' => 'delivered'], ['X-Twilio-Signature' => 'bogus'])
            ->assertStatus(403);
    }

    public function test_twilio_webhook_accepts_a_correctly_signed_delivered_callback(): void
    {
        $tenant = $this->provisionTenant();
        $deliveryId = null;
        $authToken = 'secret-token';

        $tenant->run(function () use (&$deliveryId, $authToken) {
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true,
                'credentials' => ['account_sid' => 'AC123', 'auth_token' => $authToken], 'config' => ['from_number' => '+10000000000'],
            ]);
            $deliveryId = $this->makeDelivery(MsgNotificationDelivery::STATUS_SENT, 'sms', 'SM999')->id;
        });

        $params = ['MessageSid' => 'SM999', 'MessageStatus' => 'delivered'];
        $url = "/api/wne/{$tenant->id}/webhooks/twilio";
        $signature = $this->twilioSignature($url, $params, $authToken);

        $this->post($url, $params, ['X-Twilio-Signature' => $signature])->assertOk();

        $tenant->run(function () use ($deliveryId) {
            $this->assertSame(MsgNotificationDelivery::STATUS_DELIVERED, MsgNotificationDelivery::query()->find($deliveryId)->status);
        });
    }

    public function test_twilio_webhook_marks_undelivered_as_bounced(): void
    {
        $tenant = $this->provisionTenant();
        $deliveryId = null;
        $authToken = 'secret-token';

        $tenant->run(function () use (&$deliveryId, $authToken) {
            MsgChannelConfig::query()->create([
                'channel' => MsgChannelConfig::CHANNEL_SMS, 'enabled' => true,
                'credentials' => ['account_sid' => 'AC123', 'auth_token' => $authToken], 'config' => ['from_number' => '+10000000000'],
            ]);
            $deliveryId = $this->makeDelivery(MsgNotificationDelivery::STATUS_SENT, 'sms', 'SM888')->id;
        });

        $params = ['MessageSid' => 'SM888', 'MessageStatus' => 'undelivered'];
        $url = "/api/wne/{$tenant->id}/webhooks/twilio";
        $signature = $this->twilioSignature($url, $params, $authToken);

        $this->post($url, $params, ['X-Twilio-Signature' => $signature])->assertOk();

        $tenant->run(function () use ($deliveryId) {
            $this->assertSame(MsgNotificationDelivery::STATUS_BOUNCED, MsgNotificationDelivery::query()->find($deliveryId)->status);
        });
    }

    private function twilioSignature(string $path, array $params, string $authToken): string
    {
        $data = url($path);
        ksort($params);
        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        return base64_encode(hash_hmac('sha1', $data, $authToken, true));
    }

    private function makeDelivery(string $status, string $channel = 'email', ?string $providerMessageId = null): MsgNotificationDelivery
    {
        $notification = MsgNotification::query()->create([
            'category_code' => 'demo.x', 'recipient_type' => 'user', 'recipient_user_id' => $this->adminId(),
            'subject' => 'Hi', 'body' => 'Hi', 'data' => [], 'status' => MsgNotification::STATUS_SENT, 'created_at' => now(),
        ]);

        return MsgNotificationDelivery::query()->create([
            'notification_id' => $notification->id, 'channel' => $channel, 'status' => $status,
            'provider_message_id' => $providerMessageId, 'sent_at' => now(),
        ]);
    }
}
