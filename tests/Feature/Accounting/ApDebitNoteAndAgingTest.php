<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApDebitNote;
use App\Modules\Accounting\Services\ApBillService;
use App\Modules\Accounting\Services\ApDebitNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E debit notes (v1: bill-linked only, issued+posted inline from the bill Show page) and AP Aging — mirrors ArCreditNoteAndAgingTest. */
class ApDebitNoteAndAgingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    private function setUpCompanyWithControl(array $attrs = []): Account
    {
        $company = $this->makeCompany($attrs);
        $apAccount = $this->makeAccount($company, ['is_control_account' => true]);
        $company->update(['ap_control_account_id' => $apAccount->id]);
        $this->makeFiscalYear($company);

        return $apAccount;
    }

    public function test_admin_can_issue_and_post_a_debit_note_against_a_bill(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$billId, $expenseAccountId] = [null, null];
        $tenant->run(function () use (&$billId, &$expenseAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;

            $bill = app(ApBillService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 300000, 'expense_account_id' => $expenseAccountId]],
                null,
            );
            app(ApBillService::class)->post($bill, $this->adminUserId());
            $billId = $bill->id;
        });

        $this->post('/accounting/ap-debit-notes', [
            'ap_bill_id' => $billId, 'debit_date' => '2026-01-10', 'amount' => 100000,
            'reason' => 'Damaged goods', 'expense_account_id' => $expenseAccountId,
        ])->assertRedirect(route('accounting.ap-bills.show', $billId));

        $tenant->run(function () use ($billId) {
            $note = ApDebitNote::query()->where('ap_bill_id', $billId)->first();
            $this->assertNotNull($note);
            $this->assertSame(ApDebitNote::STATUS_POSTED, $note->status);
            $this->assertNotNull($note->journal_id);

            $bill = ApBill::query()->find($billId);
            $this->assertEqualsWithDelta(100000.0, (float) $bill->debited_amount, 0.01);
            $this->assertEqualsWithDelta(200000.0, $bill->openBalance(), 0.01);
            $this->assertSame(ApBill::STATUS_PARTIALLY_PAID, $bill->status);
        });
    }

    public function test_store_rejects_invalid_bill_and_expense_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/ap-debit-notes', [
            'ap_bill_id' => 999999, 'debit_date' => '2026-01-10', 'amount' => 100000, 'expense_account_id' => 999999,
        ])->assertSessionHasErrors(['ap_bill_id', 'expense_account_id']);
    }

    public function test_post_rejects_amount_exceeding_open_balance_and_missing_control_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $bills = app(ApBillService::class);
            $bill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($bill, $this->adminUserId());

            $this->post('/accounting/ap-debit-notes', [
                'ap_bill_id' => $bill->id, 'debit_date' => '2026-01-10', 'amount' => 999999, 'expense_account_id' => $expenseAccount->id,
            ])->assertSessionHasErrors(['amount']);

            $company->update(['ap_control_account_id' => null]);
            $svc = app(ApDebitNoteService::class);
            $note = $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'ap_bill_id' => $bill->id, 'debit_date' => '2026-01-10', 'amount' => 50000, 'expense_account_id' => $expenseAccount->id], null);

            try {
                $svc->post($note, $this->adminUserId());
                $this->fail('Expected a ValidationException for missing AP control account.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('company_id', $e->errors());
            }

            // Already-posted debit note can't be posted again.
            $company->update(['ap_control_account_id' => $apAccount->id]);
            $note2 = $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'debit_date' => '2026-01-10', 'amount' => 1000, 'expense_account_id' => $expenseAccount->id], null);
            $svc->post($note2, $this->adminUserId());
            try {
                $svc->post($note2->fresh(), $this->adminUserId());
                $this->fail('Expected a ValidationException for already-posted debit note.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            // No open fiscal period covers the debit note's date.
            $note3 = $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'debit_date' => '2027-06-01', 'amount' => 1000, 'expense_account_id' => $expenseAccount->id], null);
            try {
                $svc->post($note3, $this->adminUserId());
                $this->fail('Expected a ValidationException for no open period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('debit_date', $e->errors());
            }
        });
    }

    public function test_create_rejects_a_foreign_currency_bill(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $bills = app(ApBillService::class);
            $bill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-USD', 'currency_code' => 'USD', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($bill, $this->adminUserId());

            $this->expectException(ValidationException::class);
            app(ApDebitNoteService::class)->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'ap_bill_id' => $bill->id,
                'debit_date' => '2026-01-10', 'amount' => 10, 'expense_account_id' => $expenseAccount->id,
            ], null);
        });
    }

    public function test_ap_aging_buckets_open_bills_by_days_past_due(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        Carbon::setTestNow('2026-06-15');

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $companyId = $company->id;
            $partner = $this->makePartner(['name' => 'Aging Vendor']);
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $bills = app(ApBillService::class);

            $current = $bills->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'bill_no' => 'B-CUR', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-07-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($current, $this->adminUserId());

            // 14 days past due -> days_1_30 bucket.
            $overdue1to30 = $bills->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'bill_no' => 'B-1TO30', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-06-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 150, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($overdue1to30, $this->adminUserId());

            $overdue = $bills->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'bill_no' => 'B-OVR', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-05-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 200, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($overdue, $this->adminUserId());

            // 75 days past due -> days_61_90 bucket.
            $overdue61to90 = $bills->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'bill_no' => 'B-61TO90', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-04-01'], [['description' => 'X', 'qty' => 1, 'unit_price' => 300, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($overdue61to90, $this->adminUserId());

            // Well past 90 days -> days_90_plus bucket.
            $overdue90plus = $bills->create(['company_id' => $companyId, 'partner_id' => $partner->id, 'bill_no' => 'B-90PLUS', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 400, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($overdue90plus, $this->adminUserId());
        });

        $this->get("/accounting/ap-aging?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ApAging/Index')
                ->has('rows', 1)
                ->where('rows.0.current', 100)
                ->where('rows.0.days_1_30', 150)
                ->where('rows.0.days_31_60', 200)
                ->where('rows.0.days_61_90', 300)
                ->where('rows.0.days_90_plus', 400));

        Carbon::setTestNow();
    }
}
