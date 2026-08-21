<?php

namespace App\Modules\WNE\Listeners;

use App\Modules\WNE\Events\NotificationRequested;
use App\Modules\WNE\Services\MessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §3I consuming §5's "consumes ... NotificationRequested from any calling module" —
 * turns the generic event any module (including WNE's own §3F escalation sweep) fires
 * into an actual MessagingService::notify() call. Queued + afterCommit: several
 * dispatchers (e.g. SlaEscalationService::escalateStep()) fire this from inside their
 * own DB::transaction() — running here before that commits would let this listener's
 * writes (and the driver job it queues) observe a state that could still roll back.
 */
class DeliverRequestedNotification implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly MessagingService $messaging) {}

    public function handle(NotificationRequested $event): void
    {
        $this->messaging->notify(
            category: $event->category,
            recipient: $event->recipient,
            subject: $event->subject ?? "Notification: {$event->category}",
            body: $event->body ?? ('New event: '.json_encode($event->payload)), // §3L not built — placeholder until template resolution exists
            data: $event->payload,
            subjectType: $event->subjectType,
            subjectId: $event->subjectId,
        );
    }
}
