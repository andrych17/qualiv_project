<?php

namespace App\Modules\Schedule\Data;

use Illuminate\Support\Carbon;

/** §3A — one renderable calendar entry: either a plain item or one expanded recurring occurrence. */
class CalendarItem
{
    public function __construct(
        public readonly int $schedItemId,
        public readonly string $uuid,
        public readonly string $type,
        public readonly string $title,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly bool $allDay,
        public readonly string $status,
        public readonly string $statusRail,
        public readonly ?string $ownerName,
        public readonly ?string $location,
        public readonly bool $isRecurringInstance,
        public readonly ?string $originalOccurrenceDate,
    ) {}
}
