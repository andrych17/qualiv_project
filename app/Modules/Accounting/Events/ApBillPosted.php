<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3E: fired after ApBillService::post() so the requesting module (Purchase, or Legal for
 * a direct expense) can update its own local status without Accounting knowing that
 * module's schema. Carries back exactly the subject_type/subject_id it was given.
 */
class ApBillPosted
{
    use Dispatchable;

    public function __construct(
        public int $billId,
        public ?string $subjectType,
        public ?int $subjectId,
    ) {}
}
