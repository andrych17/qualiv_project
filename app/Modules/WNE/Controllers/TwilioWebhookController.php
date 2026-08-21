<?php

namespace App\Modules\WNE\Controllers;

use App\Modules\WNE\Models\MsgChannelConfig;
use App\Modules\WNE\Models\MsgDeliveryEvent;
use App\Modules\WNE\Models\MsgNotificationDelivery;
use App\Modules\WNE\Services\ObservabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * §3O provider status webhook — Twilio's Status Callback POSTs form-encoded status updates
 * for a single message per request. Unlike SendGrid (§3O, no verification key stored yet),
 * Twilio's signature scheme needs only the account's own auth token — already stored in
 * `msg_channel_configs` for the outbound SmsDriver — so verification is cheap and required
 * here, not optional.
 */
class TwilioWebhookController
{
    public function __construct(private readonly ObservabilityService $observability) {}

    /** @var array<string, string> Twilio `MessageStatus` => our closed §3O event vocabulary */
    private const EVENT_MAP = [
        'sent' => MsgDeliveryEvent::EVENT_SENT,
        'delivered' => MsgDeliveryEvent::EVENT_DELIVERED,
        'undelivered' => MsgDeliveryEvent::EVENT_BOUNCED,
        'failed' => MsgDeliveryEvent::EVENT_BOUNCED,
    ];

    /** @var array<string, ?string> our event => the delivery status it implies */
    private const DELIVERY_STATUS_MAP = [
        MsgDeliveryEvent::EVENT_SENT => null, // already 'sent' from our own send path — the callback only confirms it, doesn't move the status
        MsgDeliveryEvent::EVENT_DELIVERED => MsgNotificationDelivery::STATUS_DELIVERED,
        MsgDeliveryEvent::EVENT_BOUNCED => MsgNotificationDelivery::STATUS_BOUNCED,
    ];

    public function handle(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('WNE §3O: rejected a Twilio status callback with an invalid or unverifiable X-Twilio-Signature.', [
                'url' => $request->fullUrl(),
                'message_sid' => $request->input('MessageSid'),
            ]);

            return response()->json(['error' => 'Invalid signature.'], 403);
        }

        $messageSid = $request->input('MessageSid');
        $ourEvent = self::EVENT_MAP[$request->input('MessageStatus')] ?? null;

        if ($messageSid && $ourEvent !== null) {
            $delivery = $this->observability->findByProviderMessageId($messageSid);

            if ($delivery) {
                $this->observability->ingestProviderEvent($delivery, $ourEvent, self::DELIVERY_STATUS_MAP[$ourEvent], $request->all());
            }
        }

        return response()->json(['status' => 'ok']);
    }

    private function hasValidSignature(Request $request): bool
    {
        $config = MsgChannelConfig::forChannel(MsgChannelConfig::CHANNEL_SMS);
        $authToken = $config?->credentials['auth_token'] ?? null;

        if (! $authToken) {
            return false; // no configured Twilio account to verify against — can't trust an unverifiable callback
        }

        $signature = $request->header('X-Twilio-Signature');

        if (! $signature) {
            return false;
        }

        // Twilio's own algorithm: the exact request URL, followed by every POST param's
        // name+value concatenated in sorted-by-name order, HMAC-SHA1'd with the auth token.
        $data = $request->fullUrl();
        $params = $request->request->all();
        ksort($params);

        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $signature);
    }
}
