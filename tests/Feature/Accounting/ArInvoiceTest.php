<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Models\TaxFakturPajak;
use App\Modules\Accounting\Services\ArInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D Accounts Receivable — customer invoices, the AR engine's primary screen. */
class ArInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_and_post_a_non_taxable_invoice(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $partnerId, $revenueAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$partnerId, &$revenueAccountId) {
            $company = $this->makeCompany();
            $arAccount = $this->makeAccount($company, ['is_control_account' => true, 'account_type' => Account::TYPE_ASSET, 'normal_balance' => Account::BALANCE_DEBIT]);
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $companyId = $company->id;
            $this->makeFiscalYear($company);
            $partnerId = $this->makePartner(['name' => 'Acme Customer'])->id;
            $revenueAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE, 'normal_balance' => Account::BALANCE_CREDIT])->id;
        });

        $this->get("/accounting/ar-invoices?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ArInvoices/Index'));
        $this->get("/accounting/ar-invoices/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ArInvoices/Create'));
        // No company_id query param — ArInvoiceController::formOptions()'s early-return branch.
        $this->get('/accounting/ar-invoices/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('revenueAccounts', []));

        $this->post('/accounting/ar-invoices', [
            'company_id' => $companyId, 'partner_id' => $partnerId, 'currency_code' => 'IDR',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'invoice_type' => ArInvoice::TYPE_STANDARD,
            'lines' => [['description' => 'Consulting', 'qty' => 2, 'unit_price' => 500000, 'revenue_account_id' => $revenueAccountId]],
        ])->assertRedirect();

        $invoiceId = null;
        $tenant->run(function () use (&$invoiceId, $companyId) {
            $invoiceId = ArInvoice::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/ar-invoices/{$invoiceId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ArInvoices/Show')
                ->where('invoice.status', ArInvoice::STATUS_DRAFT)
                ->has('invoice.lines', 1));

        $this->get("/accounting/ar-invoices/{$invoiceId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/ArInvoices/Edit'));

        $this->put("/accounting/ar-invoices/{$invoiceId}", [
            'partner_id' => $partnerId, 'currency_code' => 'IDR',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'invoice_type' => ArInvoice::TYPE_STANDARD,
            'lines' => [['description' => 'Consulting (revised)', 'qty' => 3, 'unit_price' => 500000, 'revenue_account_id' => $revenueAccountId]],
        ])->assertRedirect(route('accounting.ar-invoices.show', $invoiceId));

        $this->post("/accounting/ar-invoices/{$invoiceId}/post")->assertRedirect(route('accounting.ar-invoices.show', $invoiceId));

        $tenant->run(function () use ($invoiceId) {
            $invoice = ArInvoice::query()->find($invoiceId);
            $this->assertSame(ArInvoice::STATUS_POSTED, $invoice->status);
            $this->assertEqualsWithDelta(1500000.0, (float) $invoice->total_amount, 0.01);
            $this->assertNotNull($invoice->journal_id);
            $this->assertEqualsWithDelta(1500000.0, $invoice->openBalance(), 0.01);

            $journal = $invoice->journal;
            $this->assertSame('posted', $journal->status);
            $this->assertEqualsWithDelta(1500000.0, (float) $journal->lines()->sum('debit'), 0.01);
        });

        $this->delete("/accounting/ar-invoices/{$invoiceId}")->assertSessionHasErrors(['status']);
    }

    public function test_store_rejects_invalid_company_partner_currency_and_bad_lines(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $revenueAccountId] = [null, null];
        $tenant->run(function () use (&$companyId, &$revenueAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $revenueAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;
        });

        $this->post('/accounting/ar-invoices', [
            'company_id' => 999999, 'partner_id' => 999999, 'currency_code' => 'XXX',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'invoice_type' => ArInvoice::TYPE_STANDARD,
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'tax_code_id' => 999999, 'revenue_account_id' => 999999]],
        ])->assertSessionHasErrors(['company_id', 'partner_id', 'lines.0.tax_code_id', 'lines.0.revenue_account_id']);

        $this->post('/accounting/ar-invoices', [
            'company_id' => $companyId, 'partner_id' => 999999, 'currency_code' => 'IDR',
            'issue_date' => '2026-01-05', 'due_date' => '2026-01-01', 'invoice_type' => ArInvoice::TYPE_STANDARD,
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccountId]],
        ])->assertSessionHasErrors(['partner_id', 'due_date']);
    }

    public function test_update_rejects_invalid_partner_currency_and_bad_lines(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$invoiceId, $revenueAccountId] = [null, null];
        $tenant->run(function () use (&$invoiceId, &$revenueAccountId) {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $revenueAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;
            $invoiceId = ArInvoice::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'partner_id' => $partner->id,
                'invoice_no' => 'INV/TEST/1', 'currency_code' => 'IDR', 'issue_date' => '2026-01-01', 'due_date' => '2026-01-31',
                'status' => ArInvoice::STATUS_DRAFT,
            ])->id;
        });

        $this->put("/accounting/ar-invoices/{$invoiceId}", [
            'partner_id' => 999999, 'currency_code' => 'XXX',
            'issue_date' => '2026-01-05', 'due_date' => '2026-02-05', 'invoice_type' => ArInvoice::TYPE_STANDARD,
            'lines' => [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'tax_code_id' => 999999, 'revenue_account_id' => 999999]],
        ])->assertSessionHasErrors(['partner_id', 'currency_code', 'lines.0.tax_code_id', 'lines.0.revenue_account_id']);
    }

    public function test_post_rejects_empty_lines_missing_control_account_and_closed_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$noControlInvoiceId, $noLinesInvoiceId, $noPeriodInvoiceId] = [null, null, null];
        $tenant->run(function () use (&$noControlInvoiceId, &$noLinesInvoiceId, &$noPeriodInvoiceId) {
            $companyNoControl = $this->makeCompany(['legal_name' => 'No Control']);
            $this->makeFiscalYear($companyNoControl);
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($companyNoControl, ['account_type' => Account::TYPE_REVENUE]);

            $noControlInvoiceId = app(ArInvoiceService::class)->create(
                ['company_id' => $companyNoControl->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]],
                null,
            )->id;

            $companyWithControl = $this->makeCompany(['legal_name' => 'With Control']);
            $arAccount = $this->makeAccount($companyWithControl, ['is_control_account' => true]);
            $companyWithControl->update(['ar_control_account_id' => $arAccount->id]);
            $this->makeFiscalYear($companyWithControl);
            $noLinesInvoiceId = app(ArInvoiceService::class)->create(
                ['company_id' => $companyWithControl->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [],
                null,
            )->id;

            // No fiscal year at all for this company — no open period covers any date.
            $companyNoPeriod = $this->makeCompany(['legal_name' => 'No Period']);
            $arAccount2 = $this->makeAccount($companyNoPeriod, ['is_control_account' => true]);
            $companyNoPeriod->update(['ar_control_account_id' => $arAccount2->id]);
            $revenueAccount2 = $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_REVENUE]);
            $noPeriodInvoiceId = app(ArInvoiceService::class)->create(
                ['company_id' => $companyNoPeriod->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount2->id]],
                null,
            )->id;
        });

        $this->post("/accounting/ar-invoices/{$noControlInvoiceId}/post")->assertSessionHasErrors(['company_id']);
        $this->post("/accounting/ar-invoices/{$noLinesInvoiceId}/post")->assertSessionHasErrors(['lines']);
        $this->post("/accounting/ar-invoices/{$noPeriodInvoiceId}/post")->assertSessionHasErrors(['issue_date']);
    }

    public function test_post_with_a_taxable_line_issues_an_output_faktur_pajak(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$invoiceId, $ppnAccountId] = [null, null];
        $tenant->run(function () use (&$invoiceId, &$ppnAccountId) {
            $company = $this->makeCompany();
            $arAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $this->makeFiscalYear($company);
            $this->makeFakturPajakBlock($company);
            $partner = $this->makePartner(['registration_tax_id' => '01.234.567.8-901.000']);
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $taxCode = $this->makeTaxCode($company, ['rate' => 11, 'tax_type' => TaxCode::TYPE_OUTPUT]);
            $ppnAccountId = $taxCode->gl_account_id;

            $invoiceId = app(ArInvoiceService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'Taxable sale', 'qty' => 1, 'unit_price' => 1000000, 'tax_code_id' => $taxCode->id, 'revenue_account_id' => $revenueAccount->id]],
                null,
            )->id;
        });

        $this->post("/accounting/ar-invoices/{$invoiceId}/post")->assertRedirect();

        $tenant->run(function () use ($invoiceId, $ppnAccountId) {
            $invoice = ArInvoice::query()->find($invoiceId);
            $this->assertEqualsWithDelta(110000.0, (float) $invoice->tax_amount, 0.01);
            $this->assertEqualsWithDelta(1110000.0, (float) $invoice->total_amount, 0.01);

            $faktur = TaxFakturPajak::query()->where('ar_invoice_id', $invoiceId)->first();
            $this->assertNotNull($faktur);
            $this->assertSame(TaxFakturPajak::DIRECTION_OUTPUT, $faktur->direction);
            $this->assertEqualsWithDelta(110000.0, (float) $faktur->ppn_amount, 0.01);

            $this->assertTrue($invoice->journal->lines()->where('account_id', $ppnAccountId)->where('credit', 110000)->exists());
        });
    }

    /** A zero-rate tax code produces $lineTax <= 0 — the taxable-line loop's own "skip, don't post a zero PPN line" branch. */
    public function test_post_with_a_zero_rate_tax_code_line_adds_no_ppn_journal_line(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$invoiceId, $ppnAccountId] = [null, null];
        $tenant->run(function () use (&$invoiceId, &$ppnAccountId) {
            $company = $this->makeCompany();
            $arAccount = $this->makeAccount($company, ['is_control_account' => true]);
            $company->update(['ar_control_account_id' => $arAccount->id]);
            $this->makeFiscalYear($company);
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $taxCode = $this->makeTaxCode($company, ['rate' => 0, 'tax_type' => TaxCode::TYPE_OUTPUT]);
            $ppnAccountId = $taxCode->gl_account_id;

            $invoiceId = app(ArInvoiceService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'Zero-rate sale', 'qty' => 1, 'unit_price' => 1000000, 'tax_code_id' => $taxCode->id, 'revenue_account_id' => $revenueAccount->id]],
                null,
            )->id;
        });

        $this->post("/accounting/ar-invoices/{$invoiceId}/post")->assertRedirect();

        $tenant->run(function () use ($invoiceId, $ppnAccountId) {
            $invoice = ArInvoice::query()->find($invoiceId);
            $this->assertEqualsWithDelta(0.0, (float) $invoice->tax_amount, 0.01);
            $this->assertFalse($invoice->journal->lines()->where('account_id', $ppnAccountId)->exists());
        });
    }

    public function test_delete_is_allowed_only_while_draft(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $invoiceId = null;
        $tenant->run(function () use (&$invoiceId) {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);
            $invoiceId = app(ArInvoiceService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]],
                null,
            )->id;
        });

        $this->delete("/accounting/ar-invoices/{$invoiceId}")->assertRedirect();
        $tenant->run(function () use ($invoiceId) {
            $this->assertNull(ArInvoice::query()->find($invoiceId));
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
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $svc = app(ArInvoiceService::class);
            $svc->create(['company_id' => $companyId, 'partner_id' => $partnerAId, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
            $svc->create(['company_id' => $companyId, 'partner_id' => $partnerB->id, 'currency_code' => 'IDR', 'issue_date' => '2026-01-05', 'due_date' => '2026-02-05'], [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'revenue_account_id' => $revenueAccount->id]], null);
        });

        $this->get("/accounting/ar-invoices?company_id={$companyId}&partner_id={$partnerAId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('invoices', 1));
    }
}
