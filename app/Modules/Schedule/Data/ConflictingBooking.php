<?php

namespace App\Modules\Schedule\Data;

use App\Modules\Schedule\Models\SchedItem;
use Illuminate\Support\Carbon;

/** §3E — a conflicting booking found for a resource, with the actual occurrence window that overlaps (may differ from the item's own base start/end when the item recurs). */
class ConflictingBooking
{
    public function __construct(
        public readonly SchedItem $item,
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}
}
