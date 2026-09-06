<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CoretaxExportBatch;
use App\Modules\Accounting\Models\TaxPeriod;
use App\Modules\Accounting\Services\BuktiPotongService;
use App\Modules\Accounting\Services\CoretaxExportService;
use App\Modules\Accounting\Services\FakturPajakService;
use App\Modules\Accounting\Services\RecurringArTemplateService;
use App\Modules\Accounting\Services\RecurringJournalTemplateService;
use App\Modules\Accounting\Services\TaxPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Phase 4 (§3M/§3N/§3O/§3P) — inverse/side relations no controller's own eager-load already touches, mirroring the Phase 1-3 facade files. */
class Phase4FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_tax_code_and_withholding_type_company_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $taxCode = $this->makeTaxCode($company);
            $withholdingType = $this->makeWithholdingType($company);

            $this->assertSame($company->id, $taxCode->company->id);
            $this->assertSame($company->id, $withholdingType->company->id);
            $this->assertSame($taxCode->gl_account_id, $taxCode->glAccount->id);
            $this->assertSame($withholdingType->gl_payable_account_id, $withholdingType->glPayableAccount->id);
        });
    }

    public function test_faktur_pajak_number_block_and_tax_period_company_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $block = $this->makeFakturPajakBlock($company);
            $period = app(TaxPeriodService::class)->ensurePeriod($company->id, TaxPeriod::OBLIGATION_PPN, '2026-01');

            $this->assertSame($company->id, $block->company->id);
            $this->assertSame($company->id, $period->company->id);
        });
    }

    public function test_tax_faktur_pajak_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $this->makeFakturPajakBlock($company);

            $original = app(FakturPajakService::class)->issueOutput($company->id, 1, $partner->id, null, 1000000, 110000, now()->toDateString());
            $replacement = app(FakturPajakService::class)->replace($original, 900000, 99000);

            $this->assertSame($company->id, $original->fresh()->company->id);
            $this->assertSame($partner->id, $original->fresh()->partner->id);
            $this->assertSame($original->id, $replacement->replacesFaktur->id);
        });
    }

    public function test_tax_bukti_potong_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $withholdingType = $this->makeWithholdingType($company);
            $partner = $this->makePartner();

            $original = app(BuktiPotongService::class)->issue($company->id, 'BP23', 1, $withholdingType->id, $partner->id, 1000000, 20000, now()->toDateString());
            $replacement = app(BuktiPotongService::class)->replace($original, 900000, 18000);

            $this->assertSame($company->id, $original->fresh()->company->id);
            $this->assertSame($withholdingType->id, $original->fresh()->withholdingType->id);
            $this->assertSame($partner->id, $original->fresh()->partner->id);
            $this->assertSame($original->id, $replacement->replacesBuktiPotong->id);
        });
    }

    public function test_coretax_export_batch_company_relation(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = app(TaxPeriodService::class)->ensurePeriod($company->id, TaxPeriod::OBLIGATION_PPN, '2026-01');

            $batch = app(CoretaxExportService::class)->generate($company, $period, CoretaxExportBatch::TYPE_FAKTUR_KELUARAN, $this->adminUserId());

            $this->assertSame($company->id, $batch->company->id);
        });
    }

    public function test_recurring_journal_template_and_line_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $costCenter = $this->makeCostCenter($company);
            $debit = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $credit = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $template = app(RecurringJournalTemplateService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'currency_code' => 'IDR', 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['account_id' => $debit->id, 'cost_center_id' => $costCenter->id, 'debit' => 100], ['account_id' => $credit->id, 'credit' => 100]],
                $this->adminUserId(),
            );

            $this->assertSame($company->id, $template->company->id);
            $this->assertSame($this->adminUserId(), $template->createdBy->id);

            $line = $template->lines->first();
            $this->assertSame($template->id, $line->template->id);
            $this->assertSame($debit->id, $line->account->id);
            $this->assertSame($costCenter->id, $line->costCenter->id);
        });
    }

    public function test_recurring_ar_template_and_line_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $partner = $this->makePartner();
            $taxCode = $this->makeTaxCode($company);
            $revenueAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            $template = app(RecurringArTemplateService::class)->create(
                ['company_id' => $company->id, 'partner_id' => $partner->id, 'name' => 'X', 'currency_code' => 'IDR', 'invoice_type' => 'standard', 'payment_terms_days' => 30, 'recurrence_rule' => 'FREQ=MONTHLY', 'anchor_date' => '2026-01-05'],
                [['description' => 'X', 'qty' => 1, 'unit_price' => 100, 'tax_code_id' => $taxCode->id, 'revenue_account_id' => $revenueAccount->id]],
                $this->adminUserId(),
            );

            $this->assertSame($company->id, $template->company->id);
            $this->assertSame($this->adminUserId(), $template->createdBy->id);

            $line = $template->lines->first();
            $this->assertSame($template->id, $line->template->id);
            $this->assertSame($taxCode->id, $line->taxCode->id);
            $this->assertSame($revenueAccount->id, $line->revenueAccount->id);
        });
    }
}
