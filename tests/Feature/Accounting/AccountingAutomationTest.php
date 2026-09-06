<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Events\ApBillRequested;
use App\Modules\Accounting\Events\ApPaymentRequested;
use App\Modules\Accounting\Events\InvoiceRequested;
use App\Modules\Accounting\Events\JournalPostingRequested;
use App\Modules\Accounting\Events\PaymentRequested;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApPayment;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\ArPayment;
use App\Modules\Accounting\Models\GlJournal;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\ApBillService;
use App\Modules\Accounting\Services\ArInvoiceService;
use App\Modules\Accounting\Services\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * §3R Automation from ERP — the same-process facade (AccountingService) and the event-bus
 * (*Requested events + their queued listeners, QUEUE_CONNECTION=sync in tests so they run
 * inline) that let another Core/Vertical module touch financials without knowing
 * double-entry rules. No real caller exists yet (Sales/Purchase aren't built), so this is
 * the seam's own direct coverage.
 */
class AccountingAutomationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_facade_post_journal_creates_and_posts_in_one_call(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $journal = app(AccountingService::class)->postJournal([
                'company_id' => $company->id, 'fiscal_period_id' => $period->id, 'journal_date' => $period->start_date->toDateString(),
                'currency_code' => 'IDR', 'memo' => 'Automated',
            ], [
                ['account_id' => $debit->id, 'debit' => 100000],
                ['account_id' => $credit->id, 'credit' => 100000],
            ], $this->adminUserId());

            $this->assertSame(GlJournal::STATUS_POSTED, $journal->status);
        });
    }

    public function test_facade_creates_draft_invoices_bills_and_payments(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $arControlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'is_control_account' => true]);
            $apControlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT, 'is_control_account' => true]);
            $company->update(['ar_control_account_id' => $arControlAccount->id, 'ap_control_account_id' => $apControlAccount->id]);

            $service = app(AccountingService::class);

            $invoice = $service->createInvoice(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 1000000, 'revenue_account_id' => $revenueAccount->id]],
                null,
            );
            $this->assertSame(ArInvoice::STATUS_DRAFT, $invoice->status);

            $bill = $service->createBill(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'AUTO-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 500000, 'expense_account_id' => $expenseAccount->id]],
                null,
            );
            $this->assertSame(ApBill::STATUS_DRAFT, $bill->status);

            app(ArInvoiceService::class)->post($invoice, $this->adminUserId());
            $payment = $service->recordPayment(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id, 'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 400000],
                [['ar_invoice_id' => $invoice->id, 'applied_amount' => 400000]],
                null,
            );
            $this->assertSame(ArPayment::STATUS_DRAFT, $payment->status);

            app(ApBillService::class)->post($bill, $this->adminUserId());
            $apPayment = $service->recordApPayment(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id, 'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 200000],
                [['ap_bill_id' => $bill->id, 'applied_amount' => 200000]],
                null,
            );
            $this->assertSame(ApPayment::STATUS_DRAFT, $apPayment->status);
        });
    }

    public function test_facade_get_account_balance_and_open_ar_balance(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT, 'account_code' => 'REV1']);
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $arControlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'is_control_account' => true]);
            $company->update(['ar_control_account_id' => $arControlAccount->id]);

            app(JournalService::class)->post($this->makeJournal($company, $period, ['debit_account' => $offsetAccount, 'credit_account' => $revenueAccount, 'amount' => 300000]), $this->adminUserId());

            $balance = app(AccountingService::class)->getAccountBalance($company->id, 'REV1');
            $this->assertEqualsWithDelta(300000.0, $balance, 0.01);

            $invoice = app(ArInvoiceService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 900000, 'revenue_account_id' => $revenueAccount->id]],
                null,
            );
            app(ArInvoiceService::class)->post($invoice, $this->adminUserId());

            $this->assertEqualsWithDelta(900000.0, app(AccountingService::class)->getOpenARBalance($partner->id), 0.01);
        });
    }

    public function test_invoice_requested_and_payment_requested_events_create_ar_drafts(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $arControlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET, 'is_control_account' => true]);
            $company->update(['ar_control_account_id' => $arControlAccount->id]);

            event(new InvoiceRequested(
                $company->id, $partner->id, 'IDR', '2026-01-05', '2026-02-05',
                [['description' => 'X', 'qty' => 1, 'unit_price' => 1000000, 'revenue_account_id' => $revenueAccount->id]],
            ));
            $invoice = ArInvoice::query()->where('company_id', $company->id)->first();
            $this->assertNotNull($invoice);
            $this->assertSame(ArInvoice::STATUS_DRAFT, $invoice->status);

            app(ArInvoiceService::class)->post($invoice, $this->adminUserId());

            event(new PaymentRequested(
                $company->id, $partner->id, $cashAccount->id, 'IDR', '2026-01-10', 400000,
                [['ar_invoice_id' => $invoice->id, 'applied_amount' => 400000]],
            ));
            $payment = ArPayment::query()->where('company_id', $company->id)->first();
            $this->assertNotNull($payment);
            $this->assertSame(ArPayment::STATUS_DRAFT, $payment->status);
        });
    }

    public function test_ap_bill_requested_and_ap_payment_requested_events_create_ap_drafts(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $apControlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT, 'is_control_account' => true]);
            $company->update(['ap_control_account_id' => $apControlAccount->id]);

            event(new ApBillRequested(
                $company->id, $partner->id, 'AUTO-BILL-1', 'IDR', '2026-01-05', '2026-02-05',
                [['description' => 'X', 'qty' => 1, 'unit_price' => 500000, 'expense_account_id' => $expenseAccount->id]],
            ));
            $bill = ApBill::query()->where('company_id', $company->id)->first();
            $this->assertNotNull($bill);
            $this->assertSame(ApBill::STATUS_DRAFT, $bill->status);

            app(ApBillService::class)->post($bill, $this->adminUserId());

            event(new ApPaymentRequested(
                $company->id, $partner->id, $cashAccount->id, 'IDR', '2026-01-10', 200000,
                [['ap_bill_id' => $bill->id, 'applied_amount' => 200000]],
            ));
            $payment = ApPayment::query()->where('company_id', $company->id)->first();
            $this->assertNotNull($payment);
            $this->assertSame(ApPayment::STATUS_DRAFT, $payment->status);
        });
    }

    public function test_journal_posting_requested_event_creates_and_posts_a_journal(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            event(new JournalPostingRequested(
                $company->id, $period->id, $period->start_date->toDateString(), 'IDR',
                [['account_id' => $debit->id, 'debit' => 50000], ['account_id' => $credit->id, 'credit' => 50000]],
                memo: 'Via event bus', subjectType: 'sales.orders', subjectId: '1',
            ));

            $journal = GlJournal::query()->where('company_id', $company->id)->first();
            $this->assertNotNull($journal);
            $this->assertSame(GlJournal::STATUS_POSTED, $journal->status);
            $this->assertSame('sales.orders', $journal->subject_type);
        });
    }
}
