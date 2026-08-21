<?php

namespace App\Modules\WNE\Drivers;

use App\Modules\WNE\Contracts\ChannelDriverInterface;
use App\Modules\WNE\Data\DeliveryResult;
use App\Modules\WNE\Data\NotificationMessage;
use App\Modules\WNE\Models\MsgChannelConfig;

/**
 * §3I: interface/plumbing is real (driver map entry, config gate, delivery tracking) — the
 * actual provider call is not. FCM's legacy `fcm/send` API (single static server key) was
 * shut down in mid-2024; the current v1 API needs a service-account JWT exchange, which is
 * real engineering with no credentials in this environment to build or test against. Rather
 * than ship a call to a dead endpoint or fabricate untested auth code, this driver honestly
 * reports "not implemented" even when a tenant has configured push credentials, until that
 * integration is actually built.
 */
class PushDriver implements ChannelDriverInterface
{
    public function send(NotificationMessage $message): DeliveryResult
    {
        $config = MsgChannelConfig::forChannel(MsgChannelConfig::CHANNEL_PUSH);

        if (! $config) {
            return DeliveryResult::failure('Push channel is not configured for this tenant.');
        }

        return DeliveryResult::failure('Push delivery is not yet implemented (FCM v1 service-account integration pending).');
    }
}
