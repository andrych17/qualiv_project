<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Services\RecurringGenerationService;
use Illuminate\Console\Command;

/**
 * ACCOUNTING §3P recurring transactions sweep. Tenant-scoped — run per tenant via stancl's
 * `tenants:run`, same convention as dms:apply-retention-policies/wne:*-workflow-steps.
 * Drafts only — never posts (§3P's "no silent auto-apply" rule); a human reviews each
 * generated journal/invoice before it reaches the GL.
 */
class RunRecurringTransactionsSweep extends Command
{
    protected $signature = 'accounting:run-recurring-sweep';

    protected $description = 'Draft a journal/invoice for every recurring template due today or earlier (§3P)';

    public function handle(RecurringGenerationService $generation): int
    {
        $summary = $generation->runDue();

        if ($summary['journals_generated'] || $summary['invoices_generated'] || $summary['skipped_no_period'] || $summary['deactivated']) {
            $this->info(sprintf(
                'Recurring sweep: %d journal(s) drafted, %d invoice(s) drafted, %d skipped (no open fiscal period), %d template(s) deactivated (rule exhausted).',
                $summary['journals_generated'],
                $summary['invoices_generated'],
                $summary['skipped_no_period'],
                $summary['deactivated'],
            ));
        }

        return self::SUCCESS;
    }
}
