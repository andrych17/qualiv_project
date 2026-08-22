<?php

namespace App\Modules\Schedule\Data;

use Illuminate\Support\Carbon;

/** §3F — one expanded occurrence of a recurring calendar item, with any exception applied. */
class Occurrence
{
    public function __construct(
        public readonly Carbon $originalDate,
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly string $status,
    ) {}
}
