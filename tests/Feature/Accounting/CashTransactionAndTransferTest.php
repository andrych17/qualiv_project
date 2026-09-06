<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\CashTransaction;
use App\Modules\Accounting\Models\CashTransfer;
use App\Modules\Accounting\Services\CashTransactionService;
use App\Modules\Accounting\Services\CashTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F cash in/out and inter-account transfers — both post through JournalService with source='manual' so the control-account guard applies for free. */
class CashTransactionAndTransferTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    private function setUpBankAccount(array $companyAttrs = []): BankAccount
    {
        $company = $this->makeCompany($companyAttrs);
        $this->makeFiscalYear($company);
        $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);

        return BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);
    }

    /** No company exists yet for this tenant, so CompanyContextService::resolve() itself yields no company — bankAccountOptions()/accountOptions()'s own early-return branches. */
    public function test_create_pages_default_to_empty_options_when_no_company_exists(): void
    {
        $this->loginAsAccountingAdmin();

        $this->get('/accounting/cash-transactions/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('bankAccounts', [])->where('accounts', []));
        $this->get('/accounting/cash-transfers/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('bankAccounts', []));
    }

    public function test_admin_can_record_a_cash_in_and_cash_out_transaction(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $bankAccountId, $glAccountId, $offsetAccountId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$bankAccountId, &$glAccountId, &$offsetAccountId) {
            $bankAccount = $this->setUpBankAccount();
            $companyId = $bankAccount->company_id;
            $bankAccountId = $bankAccount->id;
            $glAccountId = $bankAccount->gl_account_id;
            $offsetAccountId = $this->makeAccount($bankAccount->company, ['account_type' => Account::TYPE_REVENUE])->id;
        });

        $this->get("/accounting/cash-transactions/create?company_id={$companyId}&bank_account_id={$bankAccountId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/CashTransactions/Create'));

        $this->post('/accounting/cash-transactions', [
            'company_id' => $companyId, 'bank_account_id' => $bankAccountId, 'direction' => CashTransaction::DIRECTION_IN,
            'transaction_date' => '2026-01-10', 'amount' => 250000, 'offset_account_id' => $offsetAccountId, 'description' => 'Interest received',
        ])->assertRedirect(route('accounting.bank-accounts.show', $bankAccountId));

        $tenant->run(function () use ($bankAccountId, $glAccountId) {
            $tx = CashTransaction::query()->where('bank_account_id', $bankAccountId)->first();
            $this->assertSame(CashTransaction::STATUS_POSTED, $tx->status);
            $this->assertNotNull($tx->journal_id);
            $this->assertTrue($tx->journal->lines()->where('account_id', $glAccountId)->where('debit', 250000)->exists());
        });

        $this->post('/accounting/cash-transactions', [
            'company_id' => $companyId, 'bank_account_id' => $bankAccountId, 'direction' => CashTransaction::DIRECTION_OUT,
            'transaction_date' => '2026-01-11', 'amount' => 50000, 'offset_account_id' => $offsetAccountId, 'description' => 'Bank fee',
        ])->assertRedirect(route('accounting.bank-accounts.show', $bankAccountId));

        $tenant->run(function () use ($bankAccountId, $glAccountId) {
            $outTx = CashTransaction::query()->where('bank_account_id', $bankAccountId)->where('direction', CashTransaction::DIRECTION_OUT)->first();
            $this->assertSame(CashTransaction::STATUS_POSTED, $outTx->status);
            $this->assertTrue($outTx->journal->lines()->where('account_id', $glAccountId)->where('credit', 50000)->exists());
        });
    }

    public function test_store_rejects_invalid_company_bank_account_offset_account_and_direction(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/cash-transactions', [
            'company_id' => 999999, 'bank_account_id' => 999999, 'direction' => 'sideways',
            'transaction_date' => '2026-01-10', 'amount' => 100, 'offset_account_id' => 999999,
        ])->assertSessionHasErrors(['company_id', 'bank_account_id', 'direction', 'offset_account_id']);
    }

    /** A foreign-currency bank account exercises post()'s own isForeign fxTrio branch. */
    public function test_post_a_transaction_on_a_foreign_currency_bank_account_records_the_fx_trio(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'USD Account', 'currency_code' => 'USD', 'gl_account_id' => $glAccount->id]);
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $svc = app(CashTransactionService::class);
            $tx = $svc->create(['company_id' => $company->id, 'bank_account_id' => $bankAccount->id, 'direction' => CashTransaction::DIRECTION_IN, 'transaction_date' => '2026-01-10', 'amount' => 100, 'offset_account_id' => $offsetAccount->id], $this->adminUserId());
            $tx = $svc->post($tx, $this->adminUserId());

            $line = $tx->journal->lines()->where('account_id', $glAccount->id)->first();
            $this->assertSame('USD', $line->fx_currency_code);
            $this->assertEqualsWithDelta(100.0, (float) $line->fx_amount, 0.01);
        });
    }

    public function test_post_rejects_already_posted_and_closed_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $bankAccount = $this->setUpBankAccount();
            $offsetAccount = $this->makeAccount($bankAccount->company, ['account_type' => Account::TYPE_REVENUE]);
            $svc = app(CashTransactionService::class);

            $tx = $svc->create(['company_id' => $bankAccount->company_id, 'bank_account_id' => $bankAccount->id, 'direction' => CashTransaction::DIRECTION_IN, 'transaction_date' => '2026-01-10', 'amount' => 100, 'offset_account_id' => $offsetAccount->id], $this->adminUserId());
            $svc->post($tx, $this->adminUserId());

            try {
                $svc->post($tx->fresh(), $this->adminUserId());
                $this->fail('Expected a ValidationException for already-posted transaction.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            // A transaction dated outside any fiscal period.
            $bankAccountNoPeriod = $this->setUpBankAccount(['legal_name' => 'No Period']);
            $offsetAccount2 = $this->makeAccount($bankAccountNoPeriod->company, ['account_type' => Account::TYPE_REVENUE]);
            $tx2 = $svc->create(['company_id' => $bankAccountNoPeriod->company_id, 'bank_account_id' => $bankAccountNoPeriod->id, 'direction' => CashTransaction::DIRECTION_OUT, 'transaction_date' => '2027-06-01', 'amount' => 100, 'offset_account_id' => $offsetAccount2->id], $this->adminUserId());

            try {
                $svc->post($tx2, $this->adminUserId());
                $this->fail('Expected a ValidationException for no open period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('transaction_date', $e->errors());
            }
        });
    }

    public function test_admin_can_transfer_between_two_bank_accounts(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $fromId, $toId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$fromId, &$toId) {
            $from = $this->setUpBankAccount();
            $companyId = $from->company_id;
            $fromId = $from->id;
            $toGlAccount = $this->makeAccount($from->company, ['account_type' => Account::TYPE_ASSET]);
            $toId = BankAccount::query()->create(['company_id' => $companyId, 'name' => 'Savings', 'currency_code' => 'IDR', 'gl_account_id' => $toGlAccount->id])->id;
        });

        $this->get("/accounting/cash-transfers/create?company_id={$companyId}&bank_account_id={$fromId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/CashTransfers/Create'));

        $this->post('/accounting/cash-transfers', [
            'company_id' => $companyId, 'from_bank_account_id' => $fromId, 'to_bank_account_id' => $toId,
            'transfer_date' => '2026-01-10', 'amount' => 300000,
        ])->assertRedirect(route('accounting.bank-accounts.show', $fromId));

        $tenant->run(function () use ($fromId) {
            $transfer = CashTransfer::query()->where('from_bank_account_id', $fromId)->first();
            $this->assertSame(CashTransfer::STATUS_POSTED, $transfer->status);
            $this->assertNotNull($transfer->journal_id);
        });
    }

    public function test_store_rejects_invalid_bank_accounts_same_account_and_currency_mismatch(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $fromId, $usdBankAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$fromId, &$usdBankAccountId) {
            $from = $this->setUpBankAccount();
            $companyId = $from->company_id;
            $fromId = $from->id;
            $usdGlAccount = $this->makeAccount($from->company, ['account_type' => Account::TYPE_ASSET]);
            $usdBankAccountId = BankAccount::query()->create(['company_id' => $companyId, 'name' => 'USD Account', 'currency_code' => 'USD', 'gl_account_id' => $usdGlAccount->id])->id;
        });

        $this->post('/accounting/cash-transfers', [
            'company_id' => 999999, 'from_bank_account_id' => 999999, 'to_bank_account_id' => 999998,
            'transfer_date' => '2026-01-10', 'amount' => 100,
        ])->assertSessionHasErrors(['company_id', 'from_bank_account_id', 'to_bank_account_id']);

        $this->post('/accounting/cash-transfers', [
            'company_id' => $companyId, 'from_bank_account_id' => $fromId, 'to_bank_account_id' => $fromId,
            'transfer_date' => '2026-01-10', 'amount' => 100,
        ])->assertSessionHasErrors(['to_bank_account_id']);

        // FormRequest's own 'different' rule already blocks same-id at the HTTP layer —
        // this hits the SERVICE layer's own redundant same-account guard directly.
        $tenant->run(function () use ($companyId, $fromId, $usdBankAccountId) {
            $svc = app(CashTransferService::class);
            try {
                $svc->create(['company_id' => $companyId, 'from_bank_account_id' => $fromId, 'to_bank_account_id' => $fromId, 'transfer_date' => '2026-01-10', 'amount' => 100], null);
                $this->fail('Expected a ValidationException for same source/destination account.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('to_bank_account_id', $e->errors());
            }

            // Similarly, cross-currency is only reachable via the service layer directly
            // (create() itself throws before any FormRequest-level "different currency" rule exists).
            $this->expectException(ValidationException::class);
            $svc->create([
                'company_id' => $companyId, 'from_bank_account_id' => $fromId, 'to_bank_account_id' => $usdBankAccountId,
                'transfer_date' => '2026-01-10', 'amount' => 100,
            ], null);
        });
    }

    /** Both legs in USD while the company's base currency is IDR — exercises post()'s own isForeign fxTrio branch. */
    public function test_post_a_transfer_between_two_foreign_currency_accounts_records_the_fx_trio(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $fromGlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $from = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'USD From', 'currency_code' => 'USD', 'gl_account_id' => $fromGlAccount->id]);
            $toGlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $to = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'USD To', 'currency_code' => 'USD', 'gl_account_id' => $toGlAccount->id]);

            $svc = app(CashTransferService::class);
            $transfer = $svc->create(['company_id' => $company->id, 'from_bank_account_id' => $from->id, 'to_bank_account_id' => $to->id, 'transfer_date' => '2026-01-10', 'amount' => 100], $this->adminUserId());
            $transfer = $svc->post($transfer, $this->adminUserId());

            $line = $transfer->journal->lines()->where('account_id', $toGlAccount->id)->first();
            $this->assertSame('USD', $line->fx_currency_code);
            $this->assertEqualsWithDelta(100.0, (float) $line->fx_amount, 0.01);
        });
    }

    public function test_transfer_post_rejects_already_posted_and_closed_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $from = $this->setUpBankAccount();
            $toGlAccount = $this->makeAccount($from->company, ['account_type' => Account::TYPE_ASSET]);
            $to = BankAccount::query()->create(['company_id' => $from->company_id, 'name' => 'Savings', 'currency_code' => 'IDR', 'gl_account_id' => $toGlAccount->id]);
            $svc = app(CashTransferService::class);

            $transfer = $svc->create(['company_id' => $from->company_id, 'from_bank_account_id' => $from->id, 'to_bank_account_id' => $to->id, 'transfer_date' => '2026-01-10', 'amount' => 100], $this->adminUserId());
            $svc->post($transfer, $this->adminUserId());

            try {
                $svc->post($transfer->fresh(), $this->adminUserId());
                $this->fail('Expected a ValidationException for already-posted transfer.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            $transfer2 = $svc->create(['company_id' => $from->company_id, 'from_bank_account_id' => $from->id, 'to_bank_account_id' => $to->id, 'transfer_date' => '2027-06-01', 'amount' => 100], $this->adminUserId());

            try {
                $svc->post($transfer2, $this->adminUserId());
                $this->fail('Expected a ValidationException for no open period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('transfer_date', $e->errors());
            }
        });
    }
}
