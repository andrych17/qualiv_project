<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\RecurringArTemplate;
use App\Modules\Accounting\Models\RecurringGenerationLog;
use App\Modules\Accounting\Models\RecurringJournalTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §3P — the generation sweep: for every active template due on or before $asOf, draft a
 * document (never post — a human reviews first, same "no silent auto-apply" rule DMS's
 * retention sweep follows) and advance next_run_date. Never called directly from a
 * controller — see RunRecurringGenerationSweep console command / routes/console.php.
 *
 * Idempotency is two-layered: each occurrence is generated inside a DB::transaction() that
 * holds a row lock on the template (lockForUpdate), so a second overlapping run serializes
 * behind the first and sees the already-advanced next_run_date; recurring_generation_log's
 * own unique(template_type, template_id, run_date) constraint is the backstop in case that
 * lock discipline is ever bypassed (e.g. a future caller that doesn't go through here).
 *
 * A template that's fallen behind (cron was down, or this is its first run after being
 * created with a past anchor) catches up one occurrence per loop iteration, capped at
 * MAX_CATCHUP_PER_RUN — enough to clear a realistic backlog without one template's rule
 * looping unboundedly in a single sweep.
 */
class RecurringGenerationService
{
    private const MAX_CATCHUP_PER_RUN = 12;

    public function __construct(
        private readonly RecurrenceService $recurrence,
        private readonly JournalService $journals,
        private readonly ArInvoiceService $arInvoices,
    ) {}

    /** @return array{journals_generated:int, invoices_generated:int, skipped_no_period:int, deactivated:int} */
    public function runDue(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::now())->toDateString();
        $summary = ['journals_generated' => 0, 'invoices_generated' => 0, 'skipped_no_period' => 0, 'deactivated' => 0];

        // Plain closures with an explicit `use (&$summary)` — an arrow fn here would capture
        // $summary BY VALUE (PHP arrow functions never auto-capture by reference), silently
        // discarding every count runJournalTemplate()/runArTemplate() write into their copy.
        RecurringJournalTemplate::query()
            ->where('is_active', true)
            ->where('next_run_date', '<=', $asOf)
            ->each(function (RecurringJournalTemplate $template) use ($asOf, &$summary) {
                $this->runJournalTemplate($template->id, $asOf, $summary);
            });

        RecurringArTemplate::query()
            ->where('is_active', true)
            ->where('next_run_date', '<=', $asOf)
            ->each(function (RecurringArTemplate $template) use ($asOf, &$summary) {
                $this->runArTemplate($template->id, $asOf, $summary);
            });

        return $summary;
    }

    /** @param  array{journals_generated:int, invoices_generated:int, skipped_no_period:int, deactivated:int}  $summary */
    private function runJournalTemplate(int $templateId, string $asOf, array &$summary): void
    {
        for ($i = 0; $i < self::MAX_CATCHUP_PER_RUN; $i++) {
            $continue = DB::transaction(function () use ($templateId, $asOf, &$summary) {
                $template = RecurringJournalTemplate::query()->lockForUpdate()->find($templateId);
                if (! $template || ! $template->is_active || ! $template->next_run_date || $template->next_run_date->toDateString() > $asOf) {
                    return false;
                }
                $runDate = $template->next_run_date->toDateString();

                if ($this->alreadyGenerated(RecurringGenerationLog::TYPE_JOURNAL, $template->id, $runDate)) {
                    $this->advanceJournalTemplate($template, $runDate);

                    return true;
                }

                $period = FiscalPeriod::query()
                    ->where('company_id', $template->company_id)
                    ->where('status', FiscalPeriod::STATUS_OPEN)
                    ->where('start_date', '<=', $runDate)
                    ->where('end_date', '>=', $runDate)
                    ->first();
                if (! $period) {
                    // Stall here, don't advance — the next sweep retries the same occurrence
                    // once the operator creates the missing fiscal year/period.
                    $summary['skipped_no_period']++;

                    return false;
                }

                $lines = $template->lines->map(fn ($l) => [
                    'account_id' => $l->account_id,
                    'cost_center_id' => $l->cost_center_id,
                    'debit' => (float) $l->debit,
                    'credit' => (float) $l->credit,
                    'description' => $l->description,
                ])->all();

                $journal = $this->journals->create([
                    'company_id' => $template->company_id,
                    'fiscal_period_id' => $period->id,
                    'journal_date' => $runDate,
                    'currency_code' => $template->currency_code,
                    'memo' => $template->memo,
                    'subject_type' => 'accounting.recurring_journal_templates',
                    'subject_id' => (string) $template->id,
                ], $lines, null, 'recurring');

                $this->logGeneration(RecurringGenerationLog::TYPE_JOURNAL, $template->id, $runDate, 'accounting.gl_journals', $journal->id);
                $summary['journals_generated']++;
                $this->advanceJournalTemplate($template, $runDate, $summary);

                return true;
            });

            if (! $continue) {
                break;
            }
        }
    }

    /** @param  array{journals_generated:int, invoices_generated:int, skipped_no_period:int, deactivated:int}  $summary */
    private function runArTemplate(int $templateId, string $asOf, array &$summary): void
    {
        for ($i = 0; $i < self::MAX_CATCHUP_PER_RUN; $i++) {
            $continue = DB::transaction(function () use ($templateId, $asOf, &$summary) {
                $template = RecurringArTemplate::query()->lockForUpdate()->find($templateId);
                if (! $template || ! $template->is_active || ! $template->next_run_date || $template->next_run_date->toDateString() > $asOf) {
                    return false;
                }
                $runDate = $template->next_run_date->toDateString();

                if ($this->alreadyGenerated(RecurringGenerationLog::TYPE_AR_INVOICE, $template->id, $runDate)) {
                    $this->advanceArTemplate($template, $runDate);

                    return true;
                }

                $lines = $template->lines->map(fn ($l) => [
                    'description' => $l->description,
                    'qty' => (float) $l->qty,
                    'unit_price' => (float) $l->unit_price,
                    'discount_amount' => (float) $l->discount_amount,
                    'tax_code_id' => $l->tax_code_id,
                    'revenue_account_id' => $l->revenue_account_id,
                ])->all();

                $invoice = $this->arInvoices->create([
                    'company_id' => $template->company_id,
                    'partner_id' => $template->partner_id,
                    'currency_code' => $template->currency_code,
                    'issue_date' => $runDate,
                    'due_date' => Carbon::parse($runDate)->addDays($template->payment_terms_days)->toDateString(),
                    'invoice_type' => $template->invoice_type,
                    'subject_type' => 'accounting.recurring_ar_templates',
                    'subject_id' => $template->id,
                ], $lines, null);

                $this->logGeneration(RecurringGenerationLog::TYPE_AR_INVOICE, $template->id, $runDate, 'accounting.ar_invoices', $invoice->id);
                $summary['invoices_generated']++;
                $this->advanceArTemplate($template, $runDate, $summary);

                return true;
            });

            if (! $continue) {
                break;
            }
        }
    }

    private function alreadyGenerated(string $templateType, int $templateId, string $runDate): bool
    {
        return RecurringGenerationLog::query()
            ->where('template_type', $templateType)
            ->where('template_id', $templateId)
            ->where('run_date', $runDate)
            ->exists();
    }

    private function logGeneration(string $templateType, int $templateId, string $runDate, string $generatedSubjectType, int $generatedSubjectId): void
    {
        RecurringGenerationLog::query()->create([
            'template_type' => $templateType,
            'template_id' => $templateId,
            'run_date' => $runDate,
            'generated_subject_type' => $generatedSubjectType,
            'generated_subject_id' => $generatedSubjectId,
        ]);
    }

    /** @param  array{journals_generated:int, invoices_generated:int, skipped_no_period:int, deactivated:int}|null  $summary */
    private function advanceJournalTemplate(RecurringJournalTemplate $template, string $runDate, ?array &$summary = null): void
    {
        $next = $this->recurrence->nextOccurrenceAfter($template->recurrence_rule, $template->anchor_date, Carbon::parse($runDate));
        $template->update([
            'last_run_date' => $runDate,
            'next_run_date' => $next,
            'is_active' => $next ? $template->is_active : false,
        ]);
        if (! $next && $summary !== null) {
            $summary['deactivated']++;
        }
    }

    /** @param  array{journals_generated:int, invoices_generated:int, skipped_no_period:int, deactivated:int}|null  $summary */
    private function advanceArTemplate(RecurringArTemplate $template, string $runDate, ?array &$summary = null): void
    {
        $next = $this->recurrence->nextOccurrenceAfter($template->recurrence_rule, $template->anchor_date, Carbon::parse($runDate));
        $template->update([
            'last_run_date' => $runDate,
            'next_run_date' => $next,
            'is_active' => $next ? $template->is_active : false,
        ]);
        if (! $next && $summary !== null) {
            $summary['deactivated']++;
        }
    }
}
