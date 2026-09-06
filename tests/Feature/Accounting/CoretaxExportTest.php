<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\CoretaxExportBatch;
use App\Modules\Accounting\Models\TaxPeriod;
use App\Modules\Accounting\Services\BuktiPotongService;
use App\Modules\Accounting\Services\CoretaxExportService;
use App\Modules\Accounting\Services\FakturPajakService;
use App\Modules\Accounting\Services\TaxPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3M — Coretax XML export batches (Faktur Keluaran/Masukan, Bukti Potong), on demand per tax period. */
class CoretaxExportTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_generate_a_faktur_keluaran_export_and_download_it(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFakturPajakBlock($company);
            $partner = $this->makePartner();

            app(FakturPajakService::class)->issueOutput($company->id, 1, $partner->id, '01.234.567.8-901.000', 1000000, 110000, now()->toDateString());

            $periodId = TaxPeriod::query()->where('company_id', $companyId)->where('obligation_type', TaxPeriod::OBLIGATION_PPN)->value('id');
        });

        $this->get("/accounting/coretax-exports?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/CoretaxExports/Index')->has('periods', 1));

        $this->post('/accounting/coretax-exports', [
            'company_id' => $companyId, 'tax_period_id' => $periodId, 'batch_type' => CoretaxExportBatch::TYPE_FAKTUR_KELUARAN,
        ])->assertRedirect(route('accounting.coretax-exports.index', ['company_id' => $companyId]));

        $batchId = null;
        $tenant->run(function () use (&$batchId, $companyId) {
            $batch = CoretaxExportBatch::query()->where('company_id', $companyId)->first();
            $batchId = $batch->id;
            $this->assertSame(1, $batch->record_count);
            $this->assertNotNull($batch->generatedBy);

            $xml = Storage::disk('objects')->get($batch->object_key);
            $this->assertStringContainsString('FakturPajak', $xml);
            $this->assertStringContainsString('nomorSeriFaktur', $xml);
        });

        $this->get("/accounting/coretax-exports?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('batches', 1)->where('batches.0.record_count', 1));

        $response = $this->get("/accounting/coretax-exports/{$batchId}/download");
        $response->assertOk();
        $this->assertNotEmpty($response->streamedContent());
    }

    public function test_admin_can_generate_a_faktur_masukan_export(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $partner = $this->makePartner();

            app(FakturPajakService::class)->recordInput($company->id, 1, $partner->id, '010.000-26.00000001', 500000, 55000, now()->toDateString());

            $periodId = TaxPeriod::query()->where('company_id', $companyId)->where('obligation_type', TaxPeriod::OBLIGATION_PPN)->value('id');
        });

        $this->post('/accounting/coretax-exports', [
            'company_id' => $companyId, 'tax_period_id' => $periodId, 'batch_type' => CoretaxExportBatch::TYPE_FAKTUR_MASUKAN,
        ])->assertRedirect();

        $tenant->run(function () use ($companyId) {
            $batch = CoretaxExportBatch::query()->where('company_id', $companyId)->where('batch_type', CoretaxExportBatch::TYPE_FAKTUR_MASUKAN)->first();
            $this->assertSame(1, $batch->record_count);
        });
    }

    public function test_admin_can_generate_a_bukti_potong_export(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $periodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $withholdingType = $this->makeWithholdingType($company);
            $partner = $this->makePartner();

            app(BuktiPotongService::class)->issue($company->id, 'BP23', 1, $withholdingType->id, $partner->id, 1000000, 20000, now()->toDateString());

            $periodId = TaxPeriod::query()->where('company_id', $companyId)->where('obligation_type', TaxPeriod::OBLIGATION_PPH)->value('id');
        });

        $this->post('/accounting/coretax-exports', [
            'company_id' => $companyId, 'tax_period_id' => $periodId, 'batch_type' => CoretaxExportBatch::TYPE_BUKTI_POTONG,
        ])->assertRedirect();

        $tenant->run(function () use ($companyId) {
            $batch = CoretaxExportBatch::query()->where('company_id', $companyId)->where('batch_type', CoretaxExportBatch::TYPE_BUKTI_POTONG)->first();
            $this->assertSame(1, $batch->record_count);
            $xml = Storage::disk('objects')->get($batch->object_key);
            $this->assertStringContainsString('BuktiPotong', $xml);
        });
    }

    public function test_generate_rejects_invalid_references_and_a_period_from_another_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $otherCompanyPeriodId] = [null, null];
        $tenant->run(function () use (&$companyId, &$otherCompanyPeriodId) {
            $companyId = $this->makeCompany()->id;
            $otherCompany = $this->makeCompany(['legal_name' => 'Other']);
            $otherCompanyPeriodId = app(TaxPeriodService::class)
                ->ensurePeriod($otherCompany->id, TaxPeriod::OBLIGATION_PPN, '2026-01')->id;
        });

        $this->post('/accounting/coretax-exports', [
            'company_id' => 999999, 'tax_period_id' => 999999, 'batch_type' => CoretaxExportBatch::TYPE_BUKTI_POTONG,
        ])->assertSessionHasErrors(['company_id', 'tax_period_id']);

        $this->post('/accounting/coretax-exports', [
            'company_id' => $companyId, 'tax_period_id' => $otherCompanyPeriodId, 'batch_type' => CoretaxExportBatch::TYPE_BUKTI_POTONG,
        ])->assertSessionHasErrors(['tax_period_id']);
    }

    /** The FormRequest's `in:` rule already blocks any unknown batch type via HTTP — reach recordsFor()'s default arm directly. */
    public function test_service_rejects_an_unknown_batch_type(): void
    {
        Storage::fake('objects');
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = app(TaxPeriodService::class)->ensurePeriod($company->id, TaxPeriod::OBLIGATION_PPN, '2026-01');

            $this->expectException(\InvalidArgumentException::class);
            app(CoretaxExportService::class)->generate($company, $period, 'unknown_type', $this->adminUserId());
        });
    }
}
