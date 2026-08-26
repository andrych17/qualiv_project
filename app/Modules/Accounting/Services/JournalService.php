<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Events\JournalPosted;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3C — the single posting path (post()) every subledger action (AR/AP/asset/
 * inventory/payroll/recurring/allocation) will eventually go through. §3D's AR
 * engine is the first subledger caller, passing source: 'ar' so its journals
 * (which must debit/credit the AR control account) skip the manual-entry
 * control-account guard below — see the `source` check in post().
 *
 * WNE approval routing (postings above a configurable threshold, §3C) is
 * deliberately NOT wired here: WorkflowService::start() requires an already-
 * published workflow definition to resolve a code, and none is seeded for
 * 'accounting.journal_approval' yet. Building a parallel pending_approval/
 * approve-button flow instead would be the exact "parallel approval engine"
 * §5 says never to build. STATUS_PENDING_APPROVAL stays reserved-but-unreachable
 * until a real WNE definition exists — draft -> posted -> reversed is the whole
 * v1 lifecycle.
 */
class JournalService
{
    /**
     * $userId is nullable — same "unattended caller has no human to attribute to" case
     * ArInvoiceService::create() already allows (§3P's recurring-generation sweep runs in
     * console context with no logged-in user). post() (below) is nullable for the same
     * reason as of §3H — Inventory's movement events have no human in the loop either, and
     * unlike §3D's InvoiceRequested/§3P's recurring drafts, §3H's spec explicitly calls for
     * auto-posting: the GL entry is a mechanical translation of a fact Inventory's own
     * append-only stock_ledger has already finalized, not a new decision needing review.
     * reverse() stays non-nullable — nothing in this codebase auto-reverses a posted entry.
     *
     * @param  array{company_id:int, fiscal_period_id:int, journal_date:string, currency_code:string, memo?:?string, subject_type?:?string, subject_id?:?string}  $header
     * @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, fx_currency_code?:?string, fx_amount?:?float, fx_rate?:?float, description?:?string}>  $lines
     */
    public function create(array $header, array $lines, ?int $userId, string $source = GlJournal::SOURCE_MANUAL): GlJournal
    {
        return DB::transaction(function () use ($header, $lines, $userId, $source) {
            $journal = GlJournal::query()->create([
                ...$header,
                'uuid' => (string) Str::uuid(),
                'source' => $source,
                'status' => GlJournal::STATUS_DRAFT,
                'created_by' => $userId,
            ]);

            $this->replaceLines($journal, $lines);

            AuditLog::record([
                'company_id' => $journal->company_id,
                'action' => AuditLog::ACTION_JOURNAL_CREATED,
                'subject_type' => 'accounting.gl_journals',
                'subject_id' => $journal->id,
                'actor_id' => $userId,
            ]);

            return $journal->refresh();
        });
    }

    /** @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, fx_currency_code?:?string, fx_amount?:?float, fx_rate?:?float, description?:?string}>  $lines */
    public function update(GlJournal $journal, array $header, array $lines): GlJournal
    {
        $this->assertDraft($journal);

        return DB::transaction(function () use ($journal, $header, $lines) {
            $journal->update($header);
            $this->replaceLines($journal, $lines);

            return $journal->refresh();
        });
    }

    /** §3C: a journal must balance (Σdebit = Σcredit) before it can leave draft; control accounts reject manual lines (§3B, enforced here not just in the UI). */
    public function post(GlJournal $journal, ?int $userId): GlJournal
    {
        $this->assertDraft($journal);

        return DB::transaction(function () use ($journal, $userId) {
            $lines = $journal->lines()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'A journal needs at least one line before it can be posted.']);
            }

            $totalDebit = (float) $lines->sum('debit');
            $totalCredit = (float) $lines->sum('credit');
            if (abs($totalDebit - $totalCredit) > 0.005) {
                throw ValidationException::withMessages([
                    'lines' => "Journal is not balanced: debit {$totalDebit} vs credit {$totalCredit}.",
                ]);
            }

            // Control-account guard applies only to manual entry (§3B) — a subledger
            // journal (source 'ar'/'ap'/...) is expected to hit its control account;
            // that guarantee is enforced by the subledger's own service, not here.
            if ($journal->source === GlJournal::SOURCE_MANUAL) {
                $this->assertNoControlAccountLines($lines);
            }
            $this->assertPeriodOpen($journal->fiscalPeriod ?? $journal->fiscalPeriod()->first());

            $journal->update([
                'status' => GlJournal::STATUS_POSTED,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            AuditLog::record([
                'company_id' => $journal->company_id,
                'action' => AuditLog::ACTION_JOURNAL_POSTED,
                'subject_type' => 'accounting.gl_journals',
                'subject_id' => $journal->id,
                'actor_id' => $userId,
            ]);

            // §3R status echo — carries the journal's OWN subject_type/subject_id (what
            // triggered it, e.g. 'inventory.stock_ledger'), not the audit-log pointer above
            // (which points AT this journal for the audit trail, a different thing).
            JournalPosted::dispatch($journal->id, $journal->subject_type, $journal->subject_id);

            return $journal->refresh();
        });
    }

    /** §3C: correcting a posted entry always creates a new reversing entry — never edit-in-place. */
    public function reverse(GlJournal $journal, int $userId, ?string $memo = null): GlJournal
    {
        if ($journal->status !== GlJournal::STATUS_POSTED) {
            throw ValidationException::withMessages(['status' => 'Only a posted journal can be reversed.']);
        }

        return DB::transaction(function () use ($journal, $userId, $memo) {
            $today = now()->toDateString();
            $period = $journal->fiscalPeriod ?? $journal->fiscalPeriod()->first();

            if (! $period || $period->status !== FiscalPeriod::STATUS_OPEN) {
                $period = FiscalPeriod::query()
                    ->where('company_id', $journal->company_id)
                    ->where('status', FiscalPeriod::STATUS_OPEN)
                    ->where('start_date', '<=', $today)
                    ->where('end_date', '>=', $today)
                    ->first() ?? $period;
            }

            $this->assertPeriodOpen($period);

            $reversal = GlJournal::query()->create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $journal->company_id,
                'fiscal_period_id' => $period->id,
                'journal_date' => $today,
                'currency_code' => $journal->currency_code,
                'memo' => $memo ?? "Reversal of journal #{$journal->id}",
                'source' => $journal->source,
                'status' => GlJournal::STATUS_POSTED,
                'subject_type' => $journal->subject_type,
                'subject_id' => $journal->subject_id,
                'reversed_journal_id' => $journal->id, // set on the reversing entry, points to the original (§4 schema convention)
                'created_by' => $userId,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            foreach ($journal->lines as $line) {
                GlJournalLine::query()->create([
                    'journal_id' => $reversal->id,
                    'line_no' => $line->line_no,
                    'account_id' => $line->account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'debit' => $line->credit, // swapped — that's what makes it a reversal
                    'credit' => $line->debit,
                    'description' => $line->description,
                ]);
            }

            $journal->update(['status' => GlJournal::STATUS_REVERSED]);

            // One row for the reversal event, referencing the original journal — the new
            // reversal entry's own id is carried in after_snapshot rather than a second
            // journal_posted row, since it never went through the ordinary post() gate above.
            AuditLog::record([
                'company_id' => $journal->company_id,
                'action' => AuditLog::ACTION_JOURNAL_REVERSED,
                'subject_type' => 'accounting.gl_journals',
                'subject_id' => $journal->id,
                'actor_id' => $userId,
                'after_snapshot' => ['reversal_journal_id' => $reversal->id],
            ]);

            return $reversal;
        });
    }

    public function delete(GlJournal $journal): void
    {
        $this->assertDraft($journal);
        $journal->delete();
    }

    private function assertDraft(GlJournal $journal): void
    {
        if ($journal->status !== GlJournal::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Only a draft journal can be edited, posted, or deleted.']);
        }
    }

    /** @param  list<array{account_id:int, cost_center_id?:?int, debit?:float, credit?:float, fx_currency_code?:?string, fx_amount?:?float, fx_rate?:?float, description?:?string}>  $lines */
    private function replaceLines(GlJournal $journal, array $lines): void
    {
        $journal->lines()->delete();

        foreach (array_values($lines) as $i => $line) {
            $debit = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);

            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(['lines' => 'A line cannot have both a debit and a credit amount.']);
            }
            if ($debit <= 0 && $credit <= 0) {
                throw ValidationException::withMessages(['lines' => 'Each line needs a debit or a credit amount.']);
            }

            GlJournalLine::query()->create([
                'journal_id' => $journal->id,
                'line_no' => $i + 1,
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                // §3L: set only when the line's transaction currency differs from the
                // journal's base-currency debit/credit — see ArInvoiceService/ApBillService.
                'fx_currency_code' => $line['fx_currency_code'] ?? null,
                'fx_amount' => $line['fx_amount'] ?? null,
                'fx_rate' => $line['fx_rate'] ?? null,
                'description' => $line['description'] ?? null,
            ]);
        }
    }

    /** @param  Collection<int, GlJournalLine>  $lines */
    private function assertNoControlAccountLines($lines): void
    {
        $accountIds = $lines->pluck('account_id')->unique()->values();
        $controlAccountNames = Account::query()
            ->whereIn('id', $accountIds)
            ->where('is_control_account', true)
            ->pluck('account_name');

        if ($controlAccountNames->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'Control account(s) cannot receive a direct manual journal line: '.$controlAccountNames->implode(', ').'.',
            ]);
        }
    }

    private function assertPeriodOpen(?FiscalPeriod $period): void
    {
        if ($period === null) {
            throw ValidationException::withMessages(['fiscal_period_id' => 'Fiscal period not found.']);
        }

        // soft_closed's "elevated approval exception" (§3C) is deferred along with WNE
        // routing above — soft_closed behaves as closed until that lands.
        if ($period->status !== FiscalPeriod::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'fiscal_period_id' => "Fiscal period {$period->period_no}/{$period->fiscal_year_id} is {$period->status} — posting is blocked.",
            ]);
        }
    }
}
