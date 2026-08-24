<?php

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Events\JournalPostingRequested;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Contracts\Queue\ShouldQueue;

/** §3R consuming JournalPostingRequested — posts immediately, the event-bus mirror of AccountingService::postJournal() (see that method's docblock for why no draft step exists here). */
class PostRequestedJournal implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly JournalService $journals) {}

    public function handle(JournalPostingRequested $event): void
    {
        $journal = $this->journals->create([
            'company_id' => $event->companyId,
            'fiscal_period_id' => $event->fiscalPeriodId,
            'journal_date' => $event->journalDate,
            'currency_code' => $event->currencyCode,
            'memo' => $event->memo,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
        ], $event->lines, null, $event->source);

        $this->journals->post($journal, null);
    }
}
