<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\RecurringJournalTemplate;
use App\Modules\Accounting\Models\RecurringJournalTemplateLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** §3P — recurring journal template CRUD; RecurringGenerationService is what actually drafts a GlJournal from these. */
class RecurringJournalTemplateService
{
    public function __construct(private readonly RecurrenceService $recurrence) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, description?:?string}>  $lines
     */
    public function create(array $header, array $lines, int $userId): RecurringJournalTemplate
    {
        return DB::transaction(function () use ($header, $lines, $userId) {
            $template = RecurringJournalTemplate::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'next_run_date' => $this->computeNextRunDate($header['recurrence_rule'], $header['anchor_date'], null),
                'created_by' => $userId,
            ]);

            $this->replaceLines($template, $lines);

            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_journal_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'after_snapshot' => $template->toArray(),
            ]);

            return $template->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, description?:?string}>  $lines
     */
    public function update(RecurringJournalTemplate $template, array $header, array $lines, int $userId): RecurringJournalTemplate
    {
        return DB::transaction(function () use ($template, $header, $lines, $userId) {
            $before = $template->toArray();

            // A rule/anchor edit re-derives next_run_date from scratch — same computation
            // create() and the generation sweep both use — rather than leaving a stale value.
            $anchor = $header['anchor_date'] ?? $template->anchor_date->toDateString();
            $rule = $header['recurrence_rule'] ?? $template->recurrence_rule;
            $header['next_run_date'] = $this->computeNextRunDate($rule, $anchor, $template->last_run_date?->toDateString());

            $template->update($header);
            $this->replaceLines($template, $lines);

            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_journal_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'before_snapshot' => $before,
                'after_snapshot' => $template->toArray(),
            ]);

            return $template->refresh();
        });
    }

    public function delete(RecurringJournalTemplate $template, int $userId): void
    {
        DB::transaction(function () use ($template, $userId) {
            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_journal_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'before_snapshot' => $template->toArray(),
            ]);

            $template->delete();
        });
    }

    public function setActive(RecurringJournalTemplate $template, bool $active, int $userId): RecurringJournalTemplate
    {
        return DB::transaction(function () use ($template, $active, $userId) {
            $before = $template->toArray();
            $template->update(['is_active' => $active]);

            AuditLog::record([
                'company_id' => $template->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.recurring_journal_templates',
                'subject_id' => $template->id,
                'actor_id' => $userId,
                'before_snapshot' => $before,
                'after_snapshot' => $template->toArray(),
            ]);

            return $template->refresh();
        });
    }

    /**
     * Same exclusive-after semantics RecurringGenerationService::advanceJournalTemplate() uses,
     * so an edit that doesn't touch rule/anchor reproduces the identical next_run_date the
     * sweep already computed — not a stale or duplicate one. $lastRunDate null (never run
     * yet) searches from one day before the anchor, since RRULE's DTSTART always counts as
     * a legitimate first occurrence and nextOccurrenceAfter() is otherwise exclusive of it.
     */
    private function computeNextRunDate(string $rrule, string $anchor, ?string $lastRunDate): ?string
    {
        $anchorDate = Carbon::parse($anchor)->startOfDay();
        $searchFrom = $lastRunDate ? Carbon::parse($lastRunDate)->startOfDay() : $anchorDate->copy()->subDay();

        return $this->recurrence->nextOccurrenceAfter($rrule, $anchorDate, $searchFrom)?->toDateString();
    }

    /** @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, description?:?string}>  $lines */
    private function replaceLines(RecurringJournalTemplate $template, array $lines): void
    {
        $template->lines()->delete();

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach (array_values($lines) as $i => $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(['lines' => 'A line cannot have both a debit and a credit amount.']);
            }
            if ($debit <= 0 && $credit <= 0) {
                throw ValidationException::withMessages(['lines' => 'Each line needs a debit or a credit amount.']);
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            RecurringJournalTemplateLine::query()->create([
                'recurring_journal_template_id' => $template->id,
                'line_no' => $i + 1,
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line['description'] ?? null,
            ]);
        }

        // Unlike a one-off manual journal, this pattern is reused unattended by the generation
        // sweep — an unbalanced template would draft an unbalanced journal every occurrence
        // until someone noticed, so it's rejected here at save time instead of at post() time.
        if (abs($totalDebit - $totalCredit) > 0.005) {
            throw ValidationException::withMessages(['lines' => "Template is not balanced: debit {$totalDebit} vs credit {$totalCredit}."]);
        }
    }
}
