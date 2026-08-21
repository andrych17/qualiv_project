<?php

namespace App\Modules\WNE\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §5: "consumes ... NotificationRequested from any calling module" — the standard shape ANY
 * module (including WNE itself, e.g. §3F's escalation sweep) uses to ask §3I's
 * MessagingService to deliver something. Consumed by App\Listeners\DeliverRequestedNotification
 * (app/Modules/WNE/Listeners), registered in AppServiceProvider.
 *
 * `recipient` is an unresolved descriptor, not a resolved address — WNE must not assume any
 * other module (e.g. HCM, for an org-chart "manager of X" lookup) is installed on this tenant's
 * plan, so resolution is deferred to whatever eventually consumes this event.
 * Shapes in use today: ['type' => 'user', 'user_id' => int], ['type' => 'role', 'role' => string],
 * ['type' => 'manager_of_user', 'user_id' => int].
 *
 * `subject`/`body` are appended (not inserted) after `subjectId` so existing positional
 * dispatch call sites don't shift — §3L (dynamic templates) doesn't exist yet, so the
 * dispatching caller authors literal text; a future template-aware caller can still leave
 * these null and let MessagingService fall back to a generic category-derived message.
 */
class NotificationRequested
{
    use Dispatchable;

    public function __construct(
        public string $category,
        public array $recipient,
        public array $payload = [],
        public ?string $subjectType = null,
        public ?int $subjectId = null,
        public ?string $subject = null,
        public ?string $body = null,
    ) {}
}
