<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\BankStatementImport;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\CashTransaction;
use App\Modules\Accounting\Services\ApBillService;
use App\Modules\Accounting\Services\ApDebitNoteService;
use App\Modules\Accounting\Services\ApPaymentService;
use App\Modules\Accounting\Services\ArCreditNoteService;
use App\Modules\Accounting\Services\ArInvoiceService;
use App\Modules\Accounting\Services\ArPaymentService;
use App\Modules\Accounting\Services\CashTransactionService;
use App\Modules\Accounting\Services\CashTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Inverse/side relations no Phase-2 controller's own eager-load already touches — mirrors Phase 1's FacadeAndModelTest. */
class ArApFacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_ar_invoice_ar_payment_and_credit_note_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $arAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);

            $invoices = app(ArInvoiceService::class);
            $invoice = $invoices->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 300000, 'revenue_account_id' => $revenueAccount->id]], $this->adminUserId());
            $invoices->post($invoice, $this->adminUserId());
            $this->assertSame($invoice->id, $invoice->lines->first()->invoice->id);

            $payments = app(ArPaymentService::class);
            $payment = $payments->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id, 'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100000], [['ar_invoice_id' => $invoice->id, 'applied_amount' => 100000]], $this->adminUserId());
            $payments->post($payment, $this->adminUserId());

            $this->assertTrue($invoice->fresh()->paymentApplications->contains('ar_payment_id', $payment->id));
            $this->assertNotNull($invoice->fresh()->createdBy);
            $this->assertSame($payment->id, $payment->applications->first()->payment->id);
            $this->assertSame($partner->id, $payment->partner->id);
            $this->assertSame($cashAccount->id, $payment->cashAccount->id);
            $this->assertSame($payment->journal_id, $payment->journal->id);
            $this->assertSame($this->adminUserId(), $payment->createdBy->id);

            $notes = app(ArCreditNoteService::class);
            $note = $notes->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'ar_invoice_id' => $invoice->id, 'credit_date' => '2026-01-10', 'amount' => 50000, 'revenue_account_id' => $revenueAccount->id], $this->adminUserId());
            $notes->post($note, $this->adminUserId());

            $this->assertSame($partner->id, $note->partner->id);
            $this->assertSame($revenueAccount->id, $note->revenueAccount->id);
            $this->assertSame($note->journal_id, $note->journal->id);
            $this->assertSame($this->adminUserId(), $note->createdBy->id);
        });
    }

    public function test_ap_bill_ap_payment_and_debit_note_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $apAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $company->update(['ap_control_account_id' => $apAccount->id]);
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);

            $bills = app(ApBillService::class);
            $bill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 300000, 'expense_account_id' => $expenseAccount->id]], $this->adminUserId());
            $bills->post($bill, $this->adminUserId());
            $this->assertSame($bill->id, $bill->lines->first()->bill->id);
            $this->assertNotNull($bill->fresh()->createdBy);

            $payments = app(ApPaymentService::class);
            $payment = $payments->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id, 'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100000], [['ap_bill_id' => $bill->id, 'applied_amount' => 100000]], $this->adminUserId());
            $payments->post($payment, $this->adminUserId());

            $this->assertTrue($bill->fresh()->paymentApplications->contains('ap_payment_id', $payment->id));
            $this->assertSame($payment->id, $payment->applications->first()->payment->id);
            $this->assertSame($partner->id, $payment->partner->id);
            $this->assertSame($cashAccount->id, $payment->cashAccount->id);
            $this->assertSame($payment->journal_id, $payment->journal->id);
            $this->assertSame($this->adminUserId(), $payment->createdBy->id);

            $notes = app(ApDebitNoteService::class);
            $note = $notes->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'ap_bill_id' => $bill->id, 'debit_date' => '2026-01-10', 'amount' => 50000, 'expense_account_id' => $expenseAccount->id], $this->adminUserId());
            $notes->post($note, $this->adminUserId());

            $this->assertSame($partner->id, $note->partner->id);
            $this->assertSame($expenseAccount->id, $note->expenseAccount->id);
            $this->assertSame($note->journal_id, $note->journal->id);
            $this->assertSame($this->adminUserId(), $note->createdBy->id);
        });
    }

    public function test_bank_statement_and_cash_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $bankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Main', 'currency_code' => 'IDR', 'gl_account_id' => $glAccount->id]);

            $cashTransactions = app(CashTransactionService::class);
            $tx = $cashTransactions->create(['company_id' => $company->id, 'bank_account_id' => $bankAccount->id, 'direction' => CashTransaction::DIRECTION_IN, 'transaction_date' => '2026-01-10', 'amount' => 100, 'offset_account_id' => $offsetAccount->id], $this->adminUserId());
            $cashTransactions->post($tx, $this->adminUserId());
            $this->assertSame($tx->journal_id, $tx->journal->id);
            $this->assertSame($offsetAccount->id, $tx->offsetAccount->id);

            $toGlAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $toBankAccount = BankAccount::query()->create(['company_id' => $company->id, 'name' => 'Savings', 'currency_code' => 'IDR', 'gl_account_id' => $toGlAccount->id]);
            $cashTransfers = app(CashTransferService::class);
            $transfer = $cashTransfers->create(['company_id' => $company->id, 'from_bank_account_id' => $bankAccount->id, 'to_bank_account_id' => $toBankAccount->id, 'transfer_date' => '2026-01-10', 'amount' => 50], $this->adminUserId());
            $cashTransfers->post($transfer, $this->adminUserId());
            $this->assertSame($transfer->journal_id, $transfer->journal->id);

            $import = BankStatementImport::query()->create(['company_id' => $company->id, 'bank_account_id' => $bankAccount->id, 'object_key' => 'k', 'original_filename' => 's.csv', 'line_count' => 1, 'imported_by' => $this->adminUserId(), 'imported_at' => now()]);
            $line = BankStatementLine::query()->create(['import_id' => $import->id, 'line_date' => '2026-01-10', 'description' => 'X', 'amount' => 100, 'status' => BankStatementLine::STATUS_UNMATCHED]);

            $this->assertTrue($import->lines->contains('id', $line->id));
            $this->assertSame($import->id, $line->import->id);
            $this->assertNull($line->matchedJournalLine);
        });
    }
}
