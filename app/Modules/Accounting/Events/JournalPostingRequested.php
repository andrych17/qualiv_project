<?php

namespace App\Modules\Accounting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * §3R: the event-bus door into AccountingService::postJournal() — for a caller that wants a
 * journal created AND posted without a same-process call. Consumed by
 * App\Modules\Accounting\Listeners\PostRequestedJournal, which posts immediately (same "the
 * caller already decided" reasoning as postJournal() itself — see AccountingService's class
 * docblock).
 *
 * @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, fx_currency_code?:?string, fx_amount?:?float, fx_rate?:?float, description?:?string}>  $lines
 */
class JournalPostingRequested
{
    use Dispatchable;

    public function __construct(
        public int $companyId,
        public int $fiscalPeriodId,
        public string $journalDate,
        public string $currencyCode,
        public array $lines,
        public ?string $memo = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public string $source = 'manual',
    ) {}
}
