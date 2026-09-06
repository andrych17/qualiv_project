<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApPayment;
use App\Modules\Accounting\Services\ApBillService;
use App\Modules\Accounting\Services\ApPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E — vendor payment application: create()+post() run together from the guided HTTP form (mirrors ArPaymentTest). */
class ApPaymentTest extends TestCase
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

    public function test_admin_can_record_and_post_a_payment_with_explicit_applications(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $billId, $cashAccountId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$billId, &$cashAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $companyId = $company->id;
            $partner = $this->makePartner();
            $partnerId = $partner->id;
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $cashAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;

            $bill = app(ApBillService::class)->create(
                ['company_id' => $companyId, 'partner_id' => $partnerId, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 200000, 'expense_account_id' => $expenseAccount->id]],
                null,
            );
            app(ApBillService::class)->post($bill, $this->adminUserId());
            $billId = $bill->id;
        });

        $this->get("/accounting/ap-payments?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ApPayments/Index'));
        $this->get("/accounting/ap-payments/create?company_id={$companyId}&partner_id={$partnerId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ApPayments/Create')->has('openBills', 1));
        $this->getJson("/accounting/ap-payments/open-bills?company_id={$companyId}&partner_id={$partnerId}")->assertOk()->assertJsonCount(1);
        // No company_id query param — ApPaymentController::cashAccounts()'s early-return branch.
        $this->get('/accounting/ap-payments/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('cashAccounts', []));

        $this->post('/accounting/ap-payments', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'cash_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 200000,
            'applications' => [['ap_bill_id' => $billId, 'applied_amount' => 200000]],
        ])->assertRedirect(route('accounting.ap-payments.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($billId, $companyId) {
            $payment = ApPayment::query()->where('company_id', $companyId)->first();
            $this->assertSame(ApPayment::STATUS_POSTED, $payment->status);
            $this->assertNotNull($payment->journal_id);

            $bill = ApBill::query()->find($billId);
            $this->assertSame(ApBill::STATUS_PAID, $bill->status);
            $this->assertEqualsWithDelta(0.0, $bill->openBalance(), 0.01);
        });
    }

    public function test_payment_auto_applies_oldest_due_first_when_applications_omitted(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $olderBillId, $newerBillId, $cashAccountId] = [null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$olderBillId, &$newerBillId, &$cashAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $companyId = $company->id;
            $partner = $this->makePartner();
            $partnerId = $partner->id;
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $cashAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;

            $bills = app(ApBillService::class);
            $older = $bills->create(['company_id' => $companyId, 'partner_id' => $partnerId, 'bill_no' => 'B-OLD', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($older, $this->adminUserId());
            $olderBillId = $older->id;

            $newer = $bills->create(['company_id' => $companyId, 'partner_id' => $partnerId, 'bill_no' => 'B-NEW', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-02-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($newer, $this->adminUserId());
            $newerBillId = $newer->id;
        });

        $this->post('/accounting/ap-payments', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'cash_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100000,
        ])->assertRedirect();

        $tenant->run(function () use ($olderBillId, $newerBillId) {
            $this->assertSame(ApBill::STATUS_PAID, ApBill::query()->find($olderBillId)->status);
            $this->assertSame(ApBill::STATUS_POSTED, ApBill::query()->find($newerBillId)->status);
        });
    }

    public function test_store_rejects_invalid_company_partner_cash_account_currency_and_bad_applications(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $cashAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$cashAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $companyId = $apAccount->company_id;
            $partnerId = $this->makePartner()->id;
            $cashAccountId = $this->makeAccount($apAccount->company, ['account_type' => Account::TYPE_ASSET])->id;
        });

        $this->post('/accounting/ap-payments', [
            'company_id' => 999999, 'partner_id' => 999999, 'cash_gl_account_id' => 999999, 'currency_code' => 'XXX',
            'payment_date' => '2026-01-10', 'amount' => 100, 'applications' => [['ap_bill_id' => 999999, 'applied_amount' => 100]],
        ])->assertSessionHasErrors(['company_id', 'partner_id', 'cash_gl_account_id', 'currency_code', 'applications.0.ap_bill_id']);

        $this->post('/accounting/ap-payments', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'cash_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100, 'applications' => [],
        ])->assertSessionHasErrors(['applications']);
    }

    public function test_auto_apply_throws_when_amount_exceeds_total_open_balance(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $bills = app(ApBillService::class);
            $bill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($bill, $this->adminUserId());

            $this->expectException(ValidationException::class);
            app(ApPaymentService::class)->create([
                'company_id' => $company->id, 'partner_id' => $partner->id,
                'cash_gl_account_id' => $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 999999,
            ], null, null);
        });
    }

    public function test_assert_applications_valid_rejects_amount_mismatch_wrong_partner_wrong_currency_and_over_application(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $otherPartner = $this->makePartner(['name' => 'Other']);
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $cashAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $bills = app(ApBillService::class);
            $bill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100000, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($bill, $this->adminUserId());
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);
            $foreignBill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-USD', 'currency_code' => 'USD', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-15'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($foreignBill, $this->adminUserId());

            $svc = app(ApPaymentService::class);

            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100000], [['ap_bill_id' => $bill->id, 'applied_amount' => 50000]], null);
                $this->fail('Expected a ValidationException for amount mismatch.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }

            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $otherPartner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100000], [['ap_bill_id' => $bill->id, 'applied_amount' => 100000]], null);
                $this->fail('Expected a ValidationException for wrong partner.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }

            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 100], [['ap_bill_id' => $foreignBill->id, 'applied_amount' => 100]], null);
                $this->fail('Expected a ValidationException for currency mismatch.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }

            try {
                $svc->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccountId, 'currency_code' => 'IDR', 'payment_date' => '2026-01-20', 'amount' => 500000], [['ap_bill_id' => $bill->id, 'applied_amount' => 500000]], null);
                $this->fail('Expected a ValidationException for over-application.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('applications', $e->errors());
            }
        });
    }

    public function test_post_rejects_missing_control_account_already_posted_and_closed_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $partner = $this->makePartner();
            $bills = app(ApBillService::class);
            $payments = app(ApPaymentService::class);

            $companyNoControl = $this->makeCompany(['legal_name' => 'No Control']);
            $tempApAccount = $this->makeAccount($companyNoControl, ['is_control_account' => true]);
            $companyNoControl->update(['ap_control_account_id' => $tempApAccount->id]);
            $this->makeFiscalYear($companyNoControl);
            $expenseAccount = $this->makeAccount($companyNoControl, ['account_type' => Account::TYPE_EXPENSE]);
            $bill = $bills->create(['company_id' => $companyNoControl->id, 'partner_id' => $partner->id, 'bill_no' => 'B-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($bill, $this->adminUserId());
            $companyNoControl->update(['ap_control_account_id' => null]);
            $cashAccount = $this->makeAccount($companyNoControl, ['account_type' => Account::TYPE_ASSET]);

            $payment = $payments->create([
                'company_id' => $companyNoControl->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ap_bill_id' => $bill->id, 'applied_amount' => 100]], null);

            try {
                $payments->post($payment, $this->adminUserId());
                $this->fail('Expected a ValidationException for missing AP control account.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('company_id', $e->errors());
            }

            $apAccount = $this->setUpCompanyWithControl(['legal_name' => 'With Control']);
            $company = $apAccount->company;
            $expenseAccount2 = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $bill2 = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-2', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount2->id]], null);
            $bills->post($bill2, $this->adminUserId());
            $postedPayment = $payments->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ap_bill_id' => $bill2->id, 'applied_amount' => 100]], null);
            $payments->post($postedPayment, $this->adminUserId());

            try {
                $payments->post($postedPayment->fresh(), $this->adminUserId());
                $this->fail('Expected a ValidationException for already-posted payment.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('status', $e->errors());
            }

            $companyNoPeriod = $this->makeCompany(['legal_name' => 'No Period']);
            $apAccount2 = $this->makeAccount($companyNoPeriod, ['is_control_account' => true]);
            $companyNoPeriod->update(['ap_control_account_id' => $apAccount2->id]);
            $this->makeFiscalYear($companyNoPeriod);
            $expenseAccount3 = $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_EXPENSE]);
            $bill3 = $bills->create(['company_id' => $companyNoPeriod->id, 'partner_id' => $partner->id, 'bill_no' => 'B-3', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount3->id]], null);
            $bills->post($bill3, $this->adminUserId());
            $noPeriodPayment = $payments->create([
                'company_id' => $companyNoPeriod->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_ASSET])->id,
                'currency_code' => 'IDR', 'payment_date' => '2027-06-01', 'amount' => 100,
            ], [['ap_bill_id' => $bill3->id, 'applied_amount' => 100]], null);

            try {
                $payments->post($noPeriodPayment, $this->adminUserId());
                $this->fail('Expected a ValidationException for no open period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('payment_date', $e->errors());
            }
        });
    }

    /** A foreign-currency payment exercises post()'s own isForeign fxTrio branch (distinct from the bill's own, tested elsewhere). */
    public function test_post_a_foreign_currency_payment_records_the_fx_trio(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $this->makeExchangeRate($company, ['currency_code' => 'USD', 'effective_date' => '2026-01-01', 'rate_to_base' => 15000]);

            $bills = app(ApBillService::class);
            $bill = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-USD', 'currency_code' => 'USD', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($bill, $this->adminUserId());

            $payment = app(ApPaymentService::class)->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'USD', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ap_bill_id' => $bill->id, 'applied_amount' => 100]], null);
            $payment = app(ApPaymentService::class)->post($payment, $this->adminUserId());

            $line = $payment->journal->lines()->where('account_id', $cashAccount->id)->first();
            $this->assertSame('USD', $line->fx_currency_code);
            $this->assertEqualsWithDelta(100.0, (float) $line->fx_amount, 0.01);
        });
    }

    /** ApPaymentService::delete() has no HTTP route (payments only create()+post() together) — direct service coverage. */
    public function test_delete_is_allowed_only_while_draft(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $cashAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $bills = app(ApBillService::class);
            $payments = app(ApPaymentService::class);

            $billForDraft = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-D', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($billForDraft, $this->adminUserId());
            $draft = $payments->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ap_bill_id' => $billForDraft->id, 'applied_amount' => 100]], null);
            $payments->delete($draft);
            $this->assertNull(ApPayment::query()->find($draft->id));

            $billForPosted = $bills->create(['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-P', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $bills->post($billForPosted, $this->adminUserId());
            $posted = $payments->create([
                'company_id' => $company->id, 'partner_id' => $partner->id, 'cash_gl_account_id' => $cashAccount->id,
                'currency_code' => 'IDR', 'payment_date' => '2026-01-10', 'amount' => 100,
            ], [['ap_bill_id' => $billForPosted->id, 'applied_amount' => 100]], null);
            $payments->post($posted, $this->adminUserId());

            $this->expectException(ValidationException::class);
            $payments->delete($posted->fresh());
        });
    }
}
