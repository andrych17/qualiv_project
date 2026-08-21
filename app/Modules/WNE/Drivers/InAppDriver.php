<?php

namespace App\Modules\WNE\Drivers;

use App\Modules\WNE\Contracts\ChannelDriverInterface;
use App\Modules\WNE\Data\DeliveryResult;
use App\Modules\WNE\Data\NotificationMessage;
use App\Modules\WNE\Events\InAppNotificationCreated;

/** §3I: the msg_notifications header row already IS the in-app content — this driver just confirms delivery and signals a future real-time layer. */
class InAppDriver implements ChannelDriverInterface
{
    public function send(NotificationMessage $message): DeliveryResult
    {
        if ($message->recipientUserId === null) {
            return DeliveryResult::failure('No recipient user to deliver to in-app.');
        }

        InAppNotificationCreated::dispatch($message->recipientUserId, $message->subject, $message->body);

        return DeliveryResult::success();
    }
}
