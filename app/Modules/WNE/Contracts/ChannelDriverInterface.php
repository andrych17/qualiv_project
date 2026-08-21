<?php

namespace App\Modules\WNE\Contracts;

use App\Modules\WNE\Data\DeliveryResult;
use App\Modules\WNE\Data\NotificationMessage;

/**
 * §3I: a new channel is a new class registered in MessagingService's driver map, never a
 * core engine change — same additive-driver pattern as this codebase's other pluggable
 * strategy interfaces.
 */
interface ChannelDriverInterface
{
    public function send(NotificationMessage $message): DeliveryResult;
}
