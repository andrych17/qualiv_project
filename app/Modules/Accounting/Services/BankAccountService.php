<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Models\GlJournalLine;
use Illuminate\Support\Collection;

/** §3F — cash/bank account master, plain CRUD. */
class BankAccountService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): BankAccount
    {
        return BankAccount::query()->create($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(BankAccount $bankAccount, array $data): BankAccount
    {
        $bankAccount->update($data);

        return $bankAccount->refresh();
    }

    public function delete(BankAccount $bankAccount): void
    {
        $bankAccount->delete();
    }

    /**
     * The GL-derived cash book — see BankAccountController's class docblock for why this
     * doesn't read cash_transactions. §3Q's reconciliation worksheet reuses this exact
     * computation for "book balance" rather than re-deriving it, so the two screens can
     * never disagree about what the books say.
     *
     * @return array{rows: Collection, closingBalance: float}
     */
    public function cashBook(BankAccount $bankAccount): array
    {
        $bankAccount->loadMissing('glAccount');

        $lines = GlJournalLine::query()
            ->where('account_id', $bankAccount->gl_account_id)
            ->whereHas('journal', fn ($q) => $q->where('status', GlJournal::STATUS_POSTED))
            ->with('journal:id,journal_date,memo,source,subject_type,subject_id,status')
            ->get()
            ->sort(fn (GlJournalLine $a, GlJournalLine $b) => [$a->journal->journal_date->timestamp, $a->id] <=> [$b->journal->journal_date->timestamp, $b->id])
            ->values();

        $isDebitNormal = $bankAccount->glAccount->normal_balance === Account::BALANCE_DEBIT;
        $running = 0.0;
        $rows = $lines->map(function (GlJournalLine $line) use (&$running, $isDebitNormal) {
            $delta = $isDebitNormal
                ? (float) $line->debit - (float) $line->credit
                : (float) $line->credit - (float) $line->debit;
            $running += $delta;

            return [
                'journal_line_id' => $line->id,
                'journal_id' => $line->journal_id,
                'date' => $line->journal->journal_date->toDateString(),
                'memo' => $line->description ?? $line->journal->memo,
                'source' => $line->journal->source,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'delta' => round($delta, 2),
                'running_balance' => round($running, 2),
            ];
        });

        return ['rows' => $rows, 'closingBalance' => round($running, 2)];
    }

    public static function maskAccountNumber(?string $accountNumber): ?string
    {
        if ($accountNumber === null || $accountNumber === '') {
            return null;
        }

        $last4 = substr($accountNumber, -4);

        return str_repeat('•', max(strlen($accountNumber) - 4, 0)).$last4;
    }
}
