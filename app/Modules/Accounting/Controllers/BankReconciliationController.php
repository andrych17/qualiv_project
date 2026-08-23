<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\GlJournalLine;
use App\Modules\Accounting\Requests\StoreBankReconciliationMatchRequest;
use App\Modules\Accounting\Services\BankAccountService;
use App\Modules\Accounting\Services\BankReconciliationService;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** §3Q — bank reconciliation workspace. One bank account at a time; see service docblock for the base-currency-only restriction. */
class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $service,
        private readonly BankAccountService $bankAccountService,
    ) {}

    public function show(BankAccount $bankAccount): Response
    {
        $bankAccount->load('glAccount', 'company');

        $isBaseCurrency = $bankAccount->currency_code === $bankAccount->company->base_currency;

        if (! $isBaseCurrency) {
            return Inertia::render('Accounting/BankReconciliation/Show', [
                'bankAccount' => $this->bankAccountPayload($bankAccount),
                'unsupported' => true,
                'unmatchedStatementLines' => [],
                'unmatchedJournalLines' => [],
                'matchedLines' => [],
                'ignoredLines' => [],
                'worksheet' => null,
            ]);
        }

        $book = $this->bankAccountService->cashBook($bankAccount);

        return Inertia::render('Accounting/BankReconciliation/Show', [
            'bankAccount' => $this->bankAccountPayload($bankAccount),
            'unsupported' => false,
            'unmatchedStatementLines' => $this->service->unmatchedStatementLines($bankAccount)->map(fn (BankStatementLine $l) => [
                'id' => $l->id,
                'line_date' => $l->line_date->toDateString(),
                'description' => $l->description,
                'reference' => $l->reference,
                'amount' => (float) $l->amount,
            ])->values(),
            'unmatchedJournalLines' => $this->service->unmatchedJournalLines($bankAccount)->map(fn (GlJournalLine $l) => [
                'id' => $l->id,
                'journal_id' => $l->journal_id,
                'date' => $l->journal->journal_date->toDateString(),
                'memo' => $l->description ?? $l->journal->memo,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
            ])->values(),
            'matchedLines' => $this->service->matchedStatementLines($bankAccount)->map(fn (BankStatementLine $l) => [
                'id' => $l->id,
                'line_date' => $l->line_date->toDateString(),
                'description' => $l->description,
                'amount' => (float) $l->amount,
                'matched_at' => $l->matched_at?->toDateTimeString(),
                'journal_id' => $l->matchedJournalLine?->journal_id,
                'journal_date' => $l->matchedJournalLine?->journal?->journal_date?->toDateString(),
                'journal_memo' => $l->matchedJournalLine?->description ?? $l->matchedJournalLine?->journal?->memo,
            ])->values(),
            'ignoredLines' => $this->service->ignoredStatementLines($bankAccount)->map(fn (BankStatementLine $l) => [
                'id' => $l->id,
                'line_date' => $l->line_date->toDateString(),
                'description' => $l->description,
                'amount' => (float) $l->amount,
            ])->values(),
            'worksheet' => $this->service->worksheet($bankAccount, $book['closingBalance']),
        ]);
    }

    public function autoMatch(BankAccount $bankAccount)
    {
        $matched = $this->service->autoMatch($bankAccount, auth()->id());

        return redirect()->route('accounting.bank-reconciliation.show', $bankAccount)
            ->with('success', "{$matched} line(s) auto-matched.");
    }

    public function match(StoreBankReconciliationMatchRequest $request, BankAccount $bankAccount)
    {
        $line = BankStatementLine::query()->whereHas('import', fn ($q) => $q->where('bank_account_id', $bankAccount->id))
            ->findOrFail($request->integer('statement_line_id'));
        $journalLine = GlJournalLine::query()->findOrFail($request->integer('journal_line_id'));

        $this->service->match($bankAccount, $line, $journalLine, auth()->id());

        return redirect()->route('accounting.bank-reconciliation.show', $bankAccount)->with('success', 'Matched.');
    }

    public function unmatch(BankAccount $bankAccount, BankStatementLine $bankStatementLine)
    {
        $this->assertLineBelongsToAccount($bankAccount, $bankStatementLine);
        $this->service->unmatch($bankStatementLine);

        return redirect()->route('accounting.bank-reconciliation.show', $bankAccount)->with('success', 'Unmatched.');
    }

    public function ignore(BankAccount $bankAccount, BankStatementLine $bankStatementLine)
    {
        $this->assertLineBelongsToAccount($bankAccount, $bankStatementLine);
        $this->service->ignore($bankStatementLine);

        return redirect()->route('accounting.bank-reconciliation.show', $bankAccount)->with('success', 'Ignored.');
    }

    public function unignore(BankAccount $bankAccount, BankStatementLine $bankStatementLine)
    {
        $this->assertLineBelongsToAccount($bankAccount, $bankStatementLine);
        $this->service->unignore($bankStatementLine);

        return redirect()->route('accounting.bank-reconciliation.show', $bankAccount)->with('success', 'Restored to unmatched.');
    }

    private function assertLineBelongsToAccount(BankAccount $bankAccount, BankStatementLine $line): void
    {
        $line->loadMissing('import');
        if ($line->import->bank_account_id !== $bankAccount->id) {
            throw ValidationException::withMessages(['statement_line' => 'That statement line does not belong to this bank account.']);
        }
    }

    private function bankAccountPayload(BankAccount $bankAccount): array
    {
        return [
            'id' => $bankAccount->id,
            'company_id' => $bankAccount->company_id,
            'name' => $bankAccount->name,
            'currency_code' => $bankAccount->currency_code,
            'gl_account_label' => "{$bankAccount->glAccount->account_code} {$bankAccount->glAccount->account_name}",
        ];
    }
}
