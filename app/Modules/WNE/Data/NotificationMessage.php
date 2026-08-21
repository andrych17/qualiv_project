<?php

namespace App\Modules\WNE\Data;

/** What a ChannelDriverInterface needs to actually send one delivery attempt. */
class NotificationMessage
{
    public function __construct(
        public readonly int $deliveryId,
        public readonly ?int $recipientUserId,
        public readonly string $subject,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
    ) {}
}
