<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Models\TaxFakturPajak;
use App\Modules\Accounting\Models\WithholdingType;
use App\Modules\Accounting\Services\ApBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3E Accounts Payable — vendor bills, the AP engine's primary screen. Mirrors §3D structurally. */
class ApBillTest extends TestCase
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

    public function test_admin_can_crud_and_post_a_non_taxable_bill(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $expenseAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$expenseAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $companyId = $apAccount->company_id;
            $partnerId = $this->makePartner(['name' => 'Acme Vendor'])->id;
            $expenseAccountId = $this->makeAccount($apAccount->company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        $this->get("/accounting/ap-bills?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ApBills/Index'));
        $this->get("/accounting/ap-bills/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ApBills/Create'));
        // No company_id query param — ApBillController::formOptions()'s early-return branch.
        $this->get('/accounting/ap-bills/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('expenseAccounts', []));

        $this->post('/accounting/ap-bills', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'bill_no' => 'VND-001', 'currency_code' => 'IDR',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05',
            'lines' => [['description' => 'Office supplies', 'qty' => 2, 'unit_price' => 300000, 'expense_account_id' => $expenseAccountId]],
        ])->assertRedirect();

        $billId = null;
        $tenant->run(function () use (&$billId, $companyId) {
            $billId = ApBill::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/ap-bills/{$billId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ApBills/Show')
                ->where('bill.status', ApBill::STATUS_DRAFT)
                ->has('bill.lines', 1));

        $this->get("/accounting/ap-bills/{$billId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ApBills/Edit'));

        $this->put("/accounting/ap-bills/{$billId}", [
            'partner_id' => $partnerId, 'bill_no' => 'VND-001', 'currency_code' => 'IDR',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05',
            'lines' => [['description' => 'Office supplies (revised)', 'qty' => 3, 'unit_price' => 300000, 'expense_account_id' => $expenseAccountId]],
        ])->assertRedirect(route('accounting.ap-bills.show', $billId));

        $this->post("/accounting/ap-bills/{$billId}/post")->assertRedirect(route('accounting.ap-bills.show', $billId));

        $tenant->run(function () use ($billId) {
            $bill = ApBill::query()->find($billId);
            $this->assertSame(ApBill::STATUS_POSTED, $bill->status);
            $this->assertEqualsWithDelta(900000.0, (float) $bill->total_amount, 0.01);
            $this->assertNotNull($bill->journal_id);
            $this->assertEqualsWithDelta(900000.0, $bill->openBalance(), 0.01);

            $journal = $bill->journal;
            $this->assertSame('posted', $journal->status);
            $this->assertEqualsWithDelta(900000.0, (float) $journal->lines()->sum('credit'), 0.01);
        });

        $this->delete("/accounting/ap-bills/{$billId}")->assertSessionHasErrors(['status']);
    }

    public function test_store_rejects_invalid_company_partner_withholding_and_bad_lines(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $expenseAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$expenseAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $expenseAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        $this->post('/accounting/ap-bills', [
            'company_id' => 999999, 'partner_id' => 999999, 'bill_no' => 'X', 'currency_code' => 'XXX',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'withholding_type_id' => 999999,
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'tax_code_id' => 999999, 'expense_account_id' => 999999]],
        ])->assertSessionHasErrors(['company_id', 'partner_id', 'withholding_type_id', 'lines.0.tax_code_id', 'lines.0.expense_account_id']);

        $this->post('/accounting/ap-bills', [
            'company_id' => $companyId, 'partner_id' => 999999, 'bill_no' => 'X', 'currency_code' => 'IDR',
            'issue_date' => '2026-01-05', 'due_date' => '2026-01-01',
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccountId]],
        ])->assertSessionHasErrors(['partner_id', 'due_date']);
    }

    public function test_update_rejects_invalid_partner_withholding_and_bad_lines(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$billId, $expenseAccountId] = [null, null];
        $tenant->run(function () use (&$billId, &$expenseAccountId) {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $expenseAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $billId = ApBill::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'partner_id' => $partner->id,
                'bill_no' => 'VND-1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-31',
                'status' => ApBill::STATUS_DRAFT,
            ])->id;
        });

        $this->put("/accounting/ap-bills/{$billId}", [
            'partner_id' => 999999, 'bill_no' => 'VND-1', 'currency_code' => 'IDR', 'withholding_type_id' => 999999,
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05',
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'tax_code_id' => 999999, 'expense_account_id' => 999999]],
        ])->assertSessionHasErrors(['partner_id', 'withholding_type_id', 'lines.0.tax_code_id', 'lines.0.expense_account_id']);

        $tenant->run(function () {
            Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'is_enabled' => false]);
        });
        $this->put("/accounting/ap-bills/{$billId}", [
            'partner_id' => 999999, 'bill_no' => 'VND-1', 'currency_code' => 'EUR',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05',
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccountId]],
        ])->assertSessionHasErrors(['currency_code']);
    }

    public function test_post_rejects_empty_lines_missing_control_account_and_closed_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$noControlBillId, $noLinesBillId, $noPeriodBillId] = [null, null, null];
        $tenant->run(function () use (&$noControlBillId, &$noLinesBillId, &$noPeriodBillId) {
            $companyNoControl = $this->makeCompany(['legal_name' => 'No Control']);
            $this->makeFiscalYear($companyNoControl);
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($companyNoControl, ['account_type' => Account::TYPE_EXPENSE]);
            $noControlBillId = app(ApBillService::class)->create(
                ['company_id' => $companyNoControl->id, 'partner_id' => $partner->id, 'bill_no' => 'B1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]],
                null,
            )->id;

            $apAccount = $this->setUpCompanyWithControl(['legal_name' => 'With Control']);
            $noLinesBillId = app(ApBillService::class)->create(
                ['company_id' => $apAccount->company_id, 'partner_id' => $partner->id, 'bill_no' => 'B2', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [],
                null,
            )->id;

            $companyNoPeriod = $this->makeCompany(['legal_name' => 'No Period']);
            $apAccount2 = $this->makeAccount($companyNoPeriod, ['is_control_account' => true]);
            $companyNoPeriod->update(['ap_control_account_id' => $apAccount2->id]);
            $expenseAccount2 = $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_EXPENSE]);
            $noPeriodBillId = app(ApBillService::class)->create(
                ['company_id' => $companyNoPeriod->id, 'partner_id' => $partner->id, 'bill_no' => 'B3', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount2->id]],
                null,
            )->id;
        });

        $this->post("/accounting/ap-bills/{$noControlBillId}/post")->assertSessionHasErrors(['company_id']);
        $this->post("/accounting/ap-bills/{$noLinesBillId}/post")->assertSessionHasErrors(['lines']);
        $this->post("/accounting/ap-bills/{$noPeriodBillId}/post")->assertSessionHasErrors(['issue_date']);
    }

    public function test_post_with_a_taxable_line_records_an_input_faktur_pajak(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$billId, $ppnAccountId] = [null, null];
        $tenant->run(function () use (&$billId, &$ppnAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $taxCode = $this->makeTaxCode($company, ['rate' => 11, 'tax_type' => TaxCode::TYPE_INPUT]);
            $ppnAccountId = $taxCode->gl_account_id;

            $billId = app(ApBillService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-TAX', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'vendor_faktur_no' => '010.000-26.00000001'],
                [['description' => 'Taxable purchase', 'qty' => 1, 'unit_price' => 1000000, 'tax_code_id' => $taxCode->id, 'expense_account_id' => $expenseAccount->id]],
                null,
            )->id;
        });

        $this->post("/accounting/ap-bills/{$billId}/post")->assertRedirect();

        $tenant->run(function () use ($billId, $ppnAccountId) {
            $bill = ApBill::query()->find($billId);
            $this->assertEqualsWithDelta(110000.0, (float) $bill->tax_amount, 0.01);
            $this->assertEqualsWithDelta(1110000.0, (float) $bill->total_amount, 0.01);

            $faktur = TaxFakturPajak::query()->where('ap_bill_id', $billId)->first();
            $this->assertNotNull($faktur);
            $this->assertSame(TaxFakturPajak::DIRECTION_INPUT, $faktur->direction);

            $this->assertTrue($bill->journal->lines()->where('account_id', $ppnAccountId)->where('debit', 110000)->exists());
        });
    }

    public function test_post_with_a_taxable_line_but_no_vendor_faktur_no_is_rejected(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $taxCode = $this->makeTaxCode($company, ['rate' => 11, 'tax_type' => TaxCode::TYPE_INPUT]);

            $bill = app(ApBillService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-NOFAK', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'Taxable purchase', 'qty' => 1, 'unit_price' => 1000000, 'tax_code_id' => $taxCode->id, 'expense_account_id' => $expenseAccount->id]],
                null,
            );

            try {
                app(ApBillService::class)->post($bill, $this->adminUserId());
                $this->fail('Expected a ValidationException for missing vendor_faktur_no.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('vendor_faktur_no', $e->errors());
            }
        });
    }

    public function test_post_with_withholding_issues_a_bukti_potong_and_reduces_the_payable(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$billId, $payableAccountId] = [null, null];
        $tenant->run(function () use (&$billId, &$payableAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $withholdingType = $this->makeWithholdingType($company, ['rate' => 2]);
            $payableAccountId = $withholdingType->gl_payable_account_id;

            $billId = app(ApBillService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-WHT', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'withholding_type_id' => $withholdingType->id],
                [['description' => 'Services', 'qty' => 1, 'unit_price' => 1000000, 'expense_account_id' => $expenseAccount->id]],
                null,
            )->id;
        });

        $this->post("/accounting/ap-bills/{$billId}/post")->assertRedirect();

        $tenant->run(function () use ($billId, $payableAccountId) {
            $bill = ApBill::query()->find($billId);
            $this->assertEqualsWithDelta(20000.0, (float) $bill->withheld_amount, 0.01);
            $this->assertEqualsWithDelta(980000.0, $bill->openBalance(), 0.01);

            $bp = TaxBuktiPotong::query()->where('ap_bill_id', $billId)->first();
            $this->assertNotNull($bp);
            $this->assertEqualsWithDelta(20000.0, (float) $bp->withheld_amount, 0.01);

            $this->assertTrue($bill->journal->lines()->where('account_id', $payableAccountId)->where('credit', 20000)->exists());
        });
    }

    public function test_post_with_a_withholding_type_missing_bp_type_is_rejected(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            // makeWithholdingType()'s `??` default can't represent "explicitly null" —
            // create directly instead of going through the fixture helper for this case.
            $withholdingType = WithholdingType::query()->create([
                'company_id' => $company->id, 'code' => 'PPHBAD', 'bp_type' => null, 'name' => 'Bad Type',
                'rate' => 2, 'gl_payable_account_id' => $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id, 'is_active' => true,
            ]);

            $bill = app(ApBillService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-BADWHT', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'withholding_type_id' => $withholdingType->id],
                [['description' => 'Services', 'qty' => 1, 'unit_price' => 1000000, 'expense_account_id' => $expenseAccount->id]],
                null,
            );

            try {
                app(ApBillService::class)->post($bill, $this->adminUserId());
                $this->fail('Expected a ValidationException for missing bp_type.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('withholding_type_id', $e->errors());
            }
        });
    }

    /** A zero-rate tax code produces $lineTax <= 0 — the taxable-line loop's own "skip, don't post a zero PPN line" branch. */
    public function test_post_with_a_zero_rate_tax_code_line_adds_no_ppn_journal_line(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$billId, $ppnAccountId] = [null, null];
        $tenant->run(function () use (&$billId, &$ppnAccountId) {
            $apAccount = $this->setUpCompanyWithControl();
            $company = $apAccount->company;
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $taxCode = $this->makeTaxCode($company, ['rate' => 0, 'tax_type' => TaxCode::TYPE_INPUT]);
            $ppnAccountId = $taxCode->gl_account_id;

            $billId = app(ApBillService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-ZERO', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'Zero-rate purchase', 'qty' => 1, 'unit_price' => 1000000, 'tax_code_id' => $taxCode->id, 'expense_account_id' => $expenseAccount->id]],
                null,
            )->id;
        });

        $this->post("/accounting/ap-bills/{$billId}/post")->assertRedirect();

        $tenant->run(function () use ($billId, $ppnAccountId) {
            $bill = ApBill::query()->find($billId);
            $this->assertEqualsWithDelta(0.0, (float) $bill->tax_amount, 0.01);
            $this->assertFalse($bill->journal->lines()->where('account_id', $ppnAccountId)->exists());
        });
    }

    public function test_delete_is_allowed_only_while_draft(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $billId = null;
        $tenant->run(function () use (&$billId) {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $billId = app(ApBillService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'bill_no' => 'B-DEL', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]],
                null,
            )->id;
        });

        $this->delete("/accounting/ap-bills/{$billId}")->assertRedirect();
        $tenant->run(function () use ($billId) {
            $this->assertNull(ApBill::query()->find($billId));
        });
    }

    public function test_index_filters_by_partner(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerAId] = [null, null];
        $tenant->run(function () use (&$companyId, &$partnerAId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $partnerA = $this->makePartner(['name' => 'A']);
            $partnerAId = $partnerA->id;
            $partnerB = $this->makePartner(['name' => 'B']);
            $expenseAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);

            $svc = app(ApBillService::class);
            $svc->create(['company_id' => $companyId, 'partner_id' => $partnerAId, 'bill_no' => 'B-A', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
            $svc->create(['company_id' => $companyId, 'partner_id' => $partnerB->id, 'bill_no' => 'B-B', 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'expense_account_id' => $expenseAccount->id]], null);
        });

        $this->get("/accounting/ap-bills?company_id={$companyId}&partner_id={$partnerAId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('bills', 1));
    }
}
