<?php

namespace App\Modules\WNE\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3I: fired every time an in-app delivery succeeds. Plain (non-broadcasting) event —
 * a live badge/toast needs a real WebSocket layer (Reverb/Pusher config) this environment
 * doesn't have wired up; this is the seam a future ShouldBroadcast version attaches to,
 * not a working real-time push today.
 */
class InAppNotificationCreated
{
    use Dispatchable;

    public function __construct(public int $recipientUserId, public string $subject, public string $body) {}
}
