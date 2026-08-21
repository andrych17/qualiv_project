<?php

namespace App\Modules\WNE\Drivers;

use App\Models\User;
use App\Modules\WNE\Contracts\ChannelDriverInterface;
use App\Modules\WNE\Data\DeliveryResult;
use App\Modules\WNE\Data\NotificationMessage;
use App\Modules\WNE\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Mail;

/**
 * §3I: uses the platform's own SMTP config (config/mail.php) — unlike SMS/Push, there's no
 * per-tenant provider account to gate on; a future per-tenant "from" override could read
 * `MsgChannelConfig::forChannel('email')->config`, not built now (not asked for by §3I's text).
 */
class EmailDriver implements ChannelDriverInterface
{
    public function send(NotificationMessage $message): DeliveryResult
    {
        $user = $message->recipientUserId !== null ? User::query()->find($message->recipientUserId) : null;

        if (! $user || ! $user->email) {
            return DeliveryResult::failure('Recipient has no email address.');
        }

        try {
            $sentMessage = Mail::to($user->email)->send(new GenericNotificationMail($message->subject, $message->body));
        } catch (\Throwable $e) {
            return DeliveryResult::failure($e->getMessage());
        }

        // §3O: the same Message-ID Symfony put on the outgoing SMTP envelope is what SendGrid's
        // Event Webhook echoes back as `smtp-id` — capturing it here is what lets that webhook
        // correlate back to this exact delivery, no custom header scheme needed. Mail::fake()
        // (every test today) returns null here, same as a real send with no listener attached;
        // either way this stays optional, same posture as SMS's provider_message_id.
        return DeliveryResult::success($sentMessage?->getMessageId());
    }
}
