<?php

namespace App\Modules\WNE\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §5: "consumes ... NotificationRequested from any calling module" — the standard shape ANY
 * module (including WNE itself, e.g. §3F's escalation sweep) uses to ask the not-yet-built
 * §3I Notification engine to deliver something. No listener exists yet; this event only
 * establishes the seam so §3I can be built against real callers later.
 *
 * `recipient` is an unresolved descriptor, not a resolved address — WNE must not assume any
 * other module (e.g. HCM, for an org-chart "manager of X" lookup) is installed on this tenant's
 * plan, so resolution is deferred to whatever eventually consumes this event.
 * Shapes in use today: ['type' => 'user', 'user_id' => int], ['type' => 'role', 'role' => string],
 * ['type' => 'manager_of_user', 'user_id' => int].
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
    ) {}
}
