<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ApBillService;
use App\Modules\Accounting\Services\ArInvoiceService;
use App\Modules\Accounting\Services\ArPaymentService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3Q — AR/AP control reconciliation: a read-only trust check that the control account's GL balance agrees with the sum of open subledger items. */
class ControlReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_ar_report_matches_when_only_ar_invoice_postings_touch_the_control_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $arAccount = $this->makeAccount($company, ['is_control_account' => true, 'account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);

            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 1000000, 'revenue_account_id' => $revenueAccount->id]], null);
            $invoices->post($invoice, $this->adminUserId());

            $payments = app(ArPaymentService::class);
            $payment = $payments->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id, 'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 400000], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 400000]], null);
            $payments->post($payment, $this->adminUserId());
        });

        $this->get("/accounting/control-reconciliation?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ControlReconciliation/Index')
                ->where('ar.controlBalance', 600000)
                ->where('ar.openItemsTotal', 600000)
                ->where('ar.variance', 0)
                ->where('ar.openItemCount', 1));
    }

    public function test_ap_report_matches_when_only_ap_bill_postings_touch_the_control_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $apAccount = $this->makeAccount($company, ['is_control_account' => true, 'account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);
            $company->update(['ap_control_account_id' => $apAccount->id]);
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);

            $bills = app(ApBillService::class);
            $bill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 800000, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($bill, $this->adminUserId());
        });

        $this->get("/accounting/control-reconciliation?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('ap.controlBalance', 800000)
                ->where('ap.openItemsTotal', 800000)
                ->where('ap.variance', 0)
                ->where('ap.openItemCount', 1));
    }

    /** A manual journal touching the AR control account directly (bypassing the AR subledger entirely) is exactly the drift this report exists to surface. */
    public function test_ar_report_shows_a_variance_when_a_manual_entry_bypasses_the_subledger(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $arAccount = $this->makeAccount($company, ['is_control_account' => true, 'account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            // Directly craft+post a journal touching the AR control account with source
            // 'ar' (bypassing JournalService::post()'s manual-source control-account guard,
            // the same trick used elsewhere in this suite to simulate a subledger posting) —
            // but with NO corresponding ArInvoice row, simulating drift/data corruption.
            $journal = $this->makeJournal($company, $period, ['debit_account' => $arAccount, 'credit_account' => $offsetAccount, 'amount' => 250000, 'source' => 'ar']);
            app(JournalService::class)->post($journal, $this->adminUserId());
        });

        $this->get("/accounting/control-reconciliation?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('ar.controlBalance', 250000)
                ->where('ar.openItemsTotal', 0)
                ->where('ar.variance', 250000)
                ->where('ar.openItemCount', 0));
    }

    public function test_inventory_and_payroll_reports_show_only_the_gl_half_with_a_null_variance(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $invAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $payrollAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $company->update(['inventory_control_account_id' => $invAccount->id, 'payroll_net_pay_payable_account_id' => $payrollAccount->id]);
        });

        $this->get("/accounting/control-reconciliation?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('inventory.controlBalance', 0)
                ->where('inventory.valuationTotal', null)
                ->where('inventory.variance', null)
                ->where('payroll.controlBalance', 0)
                ->where('payroll.openTotal', null)
                ->where('payroll.variance', null));
    }

    public function test_reports_default_to_zero_when_no_control_account_is_configured_at_all(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/control-reconciliation?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('ar.controlBalance', 0)
                ->where('ap.controlBalance', 0)
                ->where('inventory.controlBalance', 0)
                ->where('payroll.controlBalance', 0));
    }
}
