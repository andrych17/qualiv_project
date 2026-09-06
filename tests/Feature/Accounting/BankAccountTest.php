<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Services\ArInvoiceService;
use App\Modules\Accounting\Services\ArPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3F Cash & Bank Management — bank/cash account master + the GL-derived cash book. */
class BankAccountTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_a_bank_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $glAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$glAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $glAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
        });

        $this->get("/accounting/bank-accounts?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankAccounts/Index'));
        $this->get("/accounting/bank-accounts/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankAccounts/Create'));
        // No company_id query param — BankAccountController::accountOptions()'s early-return branch.
        $this->get('/accounting/bank-accounts/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', []));

        $this->post('/accounting/bank-accounts', [
            'company_id' => $companyId, 'name' => 'BCA Operational', 'bank_name' => 'BCA',
            'account_number' => '1234567890', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountId,
        ])->assertRedirect(route('accounting.bank-accounts.index', ['company_id' => $companyId]));

        $bankAccountId = null;
        $tenant->run(function () use (&$bankAccountId, $companyId) {
            $bankAccountId = BankAccount::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/bank-accounts/{$bankAccountId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankAccounts/Show')
                ->where('bankAccount.account_number_masked', '••••••7890')
                ->where('closingBalance', 0));

        $this->get("/accounting/bank-accounts/{$bankAccountId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/BankAccounts/Edit'));

        $this->put("/accounting/bank-accounts/{$bankAccountId}", [
            'name' => 'BCA Operational (renamed)', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountId, 'is_active' => true,
        ])->assertRedirect(route('accounting.bank-accounts.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($bankAccountId) {
            $this->assertSame('BCA Operational (renamed)', BankAccount::query()->find($bankAccountId)->name);
        });

        $this->delete("/accounting/bank-accounts/{$bankAccountId}")->assertRedirect(route('accounting.bank-accounts.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($bankAccountId) {
            $this->assertNull(BankAccount::query()->find($bankAccountId));
        });
    }

    public function test_store_rejects_invalid_company_currency_account_and_duplicate_gl_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $glAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$glAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $glAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $this->makeAccount($company); // unused filler to keep account ids distinct
        });

        $this->post('/accounting/bank-accounts', [
            'company_id' => 999999, 'name' => 'X', 'currency_code' => 'XXX', 'gl_account_id' => 999999,
        ])->assertSessionHasErrors(['company_id', 'currency_code', 'gl_account_id']);

        $this->post('/accounting/bank-accounts', [
            'company_id' => $companyId, 'name' => 'First', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountId,
        ])->assertRedirect();

        // Same GL account reused for a second bank account is rejected (one-to-one).
        $this->post('/accounting/bank-accounts', [
            'company_id' => $companyId, 'name' => 'Second', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountId,
        ])->assertSessionHasErrors(['gl_account_id']);
    }

    public function test_update_rejects_a_gl_account_already_used_by_another_bank_account_but_allows_its_own(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountAId, $bankAccountBId, $glAccountAId] = [null, null, null];
        $tenant->run(function () use (&$bankAccountAId, &$bankAccountBId, &$glAccountAId) {
            $company = $this->makeCompany();
            $glAccountA = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $glAccountAId = $glAccountA->id;
            $glAccountB = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);

            $bankAccountAId = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'A', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountAId])->id;
            $bankAccountBId = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'B', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountB->id])->id;
        });

        $this->put("/accounting/bank-accounts/{$bankAccountBId}", [
            'name' => 'B', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountAId, 'is_active' => true,
        ])->assertSessionHasErrors(['gl_account_id']);

        // Re-saving bank account A with its own existing GL account is fine (excludes itself).
        $this->put("/accounting/bank-accounts/{$bankAccountAId}", [
            'name' => 'A (renamed)', 'currency_code' => 'IDR', 'gl_account_id' => $glAccountAId, 'is_active' => true,
        ])->assertSessionDoesntHaveErrors();

        $this->put("/accounting/bank-accounts/{$bankAccountAId}", [
            'name' => 'A', 'currency_code' => 'XXX', 'gl_account_id' => 999999, 'is_active' => true,
        ])->assertSessionHasErrors(['currency_code', 'gl_account_id']);
    }

    /** BankAccountController::show()'s cash book is GL-derived — it reflects an AR payment, not just entries made through this module's own cash-in/out forms. */
    public function test_cash_book_reflects_activity_from_any_source_touching_the_gl_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$bankAccountId] = [null];
        $tenant->run(function () use (&$bankAccountId) {
            $company = $this->makeCompany();
            $arAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $bankAccountId = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $cashAccount->id])->id;

            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 500000, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());

            $payments = app(ArPaymentService::class);
            $payment = $payments->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id, 'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 500000], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 500000]], null);
            $payments->post($payment, $this->adminUserId());
        });

        $this->get("/accounting/bank-accounts/{$bankAccountId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('lines', 1)->where('closingBalance', 500000));
    }
}
