<?php

namespace App\Modules\WNE\Drivers;

use App\Modules\WNE\Contracts\ChannelDriverInterface;
use App\Modules\WNE\Data\DeliveryResult;
use App\Modules\WNE\Data\NotificationMessage;
use App\Modules\WNE\Models\MsgChannelConfig;
use Illuminate\Support\Facades\Http;

/**
 * §3I: "tenant opt-in (credentials-gated)" — a tenant that hasn't configured Twilio in
 * WNE.msg_channel_configs simply gets a clean failure, no exception. Twilio's plain REST
 * API (basic auth) needs no SDK dependency.
 *
 * `users` has no phone column (no module owns "employee phone number" yet, e.g. HCM) — the
 * caller of MessagingService::notify() must pass the destination number via `data['phone']`.
 */
class SmsDriver implements ChannelDriverInterface
{
    public function send(NotificationMessage $message): DeliveryResult
    {
        $config = MsgChannelConfig::forChannel(MsgChannelConfig::CHANNEL_SMS);

        if (! $config) {
            return DeliveryResult::failure('SMS channel is not configured for this tenant.');
        }

        $toNumber = $message->data['phone'] ?? null;

        if (! $toNumber) {
            return DeliveryResult::failure('No destination phone number supplied.');
        }

        $credentials = $config->credentials ?? [];
        $sid = $credentials['account_sid'] ?? null;
        $token = $credentials['auth_token'] ?? null;
        $fromNumber = $config->config['from_number'] ?? null;

        if (! $sid || ! $token || ! $fromNumber) {
            return DeliveryResult::failure('SMS channel is missing required Twilio credentials.');
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $toNumber,
                'From' => $fromNumber,
                'Body' => $message->subject.': '.$message->body,
            ]);

        if (! $response->successful()) {
            return DeliveryResult::failure('Twilio error: '.($response->json('message') ?? $response->status()));
        }

        return DeliveryResult::success($response->json('sid'));
    }
}
