<?php

namespace App\Modules\WNE\Controllers;

use App\Modules\WNE\Models\MsgDeliveryEvent;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Services\ObservabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * §3O provider status webhook — SendGrid's Event Webhook POSTs a JSON array of events per
 * request (batched, no ordering guarantee, and SendGrid retries on anything but a 2xx). No
 * signature verification in v1 (SendGrid's uses Ed25519 with a per-tenant verification key
 * we have nowhere to store yet — a real gap, not an oversight): an unsigned POST can only
 * ever inject a spurious `bounced`/`delivered`/`opened` *event* for a message id an attacker
 * already knows, and even then can't move a delivery past a status our own send pipeline
 * already settled (MsgNotificationDelivery::canAdvanceStatusTo()) or reference another
 * tenant's data (the tenant id is a separate URL segment this same request already had to
 * get right to reach a real delivery at all).
 */
class SendGridWebhookController
{
    public function __construct(private readonly ObservabilityService $observability) {}

    /** @var array<string, string> SendGrid `event` => our closed §3O event vocabulary */
    private const EVENT_MAP = [
        'delivered' => MsgDeliveryEvent::EVENT_DELIVERED,
        'open' => MsgDeliveryEvent::EVENT_OPENED,
        'bounce' => MsgDeliveryEvent::EVENT_BOUNCED,
        'dropped' => MsgDeliveryEvent::EVENT_BOUNCED,
        'blocked' => MsgDeliveryEvent::EVENT_BOUNCED,
    ];

    /** @var array<string, ?string> our event => the delivery status it implies (null = event-only, e.g. 'opened' has no delivery.status) */
    private const DELIVERY_STATUS_MAP = [
        MsgDeliveryEvent::EVENT_DELIVERED => MsgNotificationDelivery::STATUS_DELIVERED,
        MsgDeliveryEvent::EVENT_OPENED => null,
        MsgDeliveryEvent::EVENT_BOUNCED => MsgNotificationDelivery::STATUS_BOUNCED,
    ];

    public function handle(Request $request): JsonResponse
    {
        foreach ((array) $request->json()->all() as $payload) {
            $this->ingestOne((array) $payload);
        }

        // Always 2xx regardless of match/skip outcomes — SendGrid retries a non-2xx for the
        // whole batch, and a webhook referencing an id we don't recognize isn't an error on
        // our end (see class docblock).
        return response()->json(['status' => 'ok']);
    }

    private function ingestOne(array $payload): void
    {
        $sendgridEvent = $payload['event'] ?? null;
        $ourEvent = self::EVENT_MAP[$sendgridEvent] ?? null;

        if ($ourEvent === null) {
            return; // outside §3O's closed event vocabulary (processed/deferred/click/unsubscribe/...) — not tracked
        }

        $smtpId = isset($payload['smtp-id']) ? trim((string) $payload['smtp-id'], "<> \t\n\r\0\x0B") : null;

        $delivery = $smtpId ? $this->observability->findByProviderMessageId($smtpId) : null;

        if (! $delivery) {
            return; // no match — see class docblock on why this is a silent no-op, not an error
        }

        $this->observability->ingestProviderEvent(
            $delivery,
            $ourEvent,
            self::DELIVERY_STATUS_MAP[$ourEvent],
            $payload,
            isset($payload['timestamp']) ? Carbon::createFromTimestamp((int) $payload['timestamp']) : null,
        );
    }
}
