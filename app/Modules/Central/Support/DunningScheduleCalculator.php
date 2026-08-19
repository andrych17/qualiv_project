<?php

namespace App\Modules\Central\Support;

use Carbon\CarbonInterface;

/** Pure "does this reminder offset fire today" check (CENTRAL_SPECS.md §3G) — negative = before due, positive = past due. */
class DunningScheduleCalculator
{
    public static function offsetDueToday(CarbonInterface $dueDate, int $offsetDays, CarbonInterface $today): bool
    {
        return $dueDate->copy()->addDays($offsetDays)->toDateString() === $today->toDateString();
    }
}
