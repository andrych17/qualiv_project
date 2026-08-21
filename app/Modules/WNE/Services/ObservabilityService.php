<?php

namespace App\Modules\WNE\Services;

use App\Modules\WNE\Models\MsgDeliveryEvent;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use Illuminate\Support\Carbon;

/**
 * §3O — ingests a provider's status webhook (SendGrid/Twilio) into the append-only
 * `msg_delivery_events` log and, where the event maps to one, advances the delivery's own
 * `status`. The two are deliberately decoupled: the event is always recorded (a webhook
 * arriving is a fact, even a stale or duplicate one), but the delivery row's status only
 * moves forward — see MsgNotificationDelivery::canAdvanceStatusTo()'s docblock for why
 * (providers redeliver and batch webhooks with no ordering guarantee).
 */
class ObservabilityService
{
    /**
     * @param  ?string  $deliveryStatus  null when the event has no corresponding delivery
     *                                   status (e.g. 'opened' — msg_notification_deliveries
     *                                   has no "opened" state, only the event is logged)
     */
    public function ingestProviderEvent(
        MsgNotificationDelivery $delivery,
        string $eventType,
        ?string $deliveryStatus,
        array $providerPayload,
        ?Carbon $occurredAt = null,
    ): void {
        MsgDeliveryEvent::log($delivery->id, $eventType, $providerPayload, $occurredAt);

        if ($deliveryStatus === null || ! $delivery->canAdvanceStatusTo($deliveryStatus)) {
            return;
        }

        $updates = ['status' => $deliveryStatus];

        if ($deliveryStatus === MsgNotificationDelivery::STATUS_DELIVERED) {
            $updates['delivered_at'] = $occurredAt ?? now();
        }

        $delivery->update($updates);
        $delivery->notification->recomputeStatus();
    }

    /**
     * Matches a provider's own message identifier back to the delivery that produced it.
     * Opportunistic and silent on a miss (no exception, no phantom event row) — a webhook
     * referencing an id we never issued (wrong tenant's leftover subscription, a provider
     * retry after the delivery/notification was deleted) is simply not our data to record.
     */
    public function findByProviderMessageId(string $providerMessageId): ?MsgNotificationDelivery
    {
        return MsgNotificationDelivery::query()->where('provider_message_id', $providerMessageId)->first();
    }
}
