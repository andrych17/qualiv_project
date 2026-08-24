<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3R: fired after JournalService::post() so a requesting module can react without polling —
 * same "read-only status echo" role as InvoicePosted/ApBillPosted (see InvoicePosted's
 * docblock). Carries back exactly the subject_type/subject_id it was given.
 */
class JournalPosted
{
    use Dispatchable;

    public function __construct(
        public int $journalId,
        public ?string $subjectType,
        public ?string $subjectId,
    ) {}
}
