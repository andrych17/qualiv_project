<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\FakturPajakNumberBlock;
use App\Modules\Accounting\Models\TaxPeriod;
use App\Modules\Accounting\Services\FakturPajakNumberBlockService;
use App\Modules\Accounting\Services\TaxPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3M — the tax period register (masa pajak) and DJP Faktur Pajak number-allocation blocks. */
class TaxPeriodAndFakturBlockTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_register_and_list_tax_periods(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/tax-periods?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/TaxPeriods/Index'));
        $this->get("/accounting/tax-periods/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/TaxPeriods/Create'));

        // A masa_pajak safely in the future so its due date is never "late" relative to whenever this test runs.
        $masaPajak = now()->addYears(2)->startOfYear()->format('Y-m');
        $this->post('/accounting/tax-periods', [
            'company_id' => $companyId, 'obligation_type' => TaxPeriod::OBLIGATION_PPN, 'masa_pajak' => $masaPajak,
        ])->assertRedirect(route('accounting.tax-periods.index', ['company_id' => $companyId]));

        $periodId = null;
        $tenant->run(function () use (&$periodId, $companyId, $masaPajak) {
            $periodId = TaxPeriod::query()->where('company_id', $companyId)->value('id');
            // PPN due day is end-of-following-month per the default config.
            $expectedDueDate = Carbon::createFromFormat('Y-m-d', "{$masaPajak}-01")->addMonthNoOverflow()->endOfMonth()->toDateString();
            $this->assertSame($expectedDueDate, TaxPeriod::query()->find($periodId)->due_date->toDateString());
        });

        $this->get("/accounting/tax-periods?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('periods', 1)->where('periods.0.filing_status', 'open'));

        $this->post("/accounting/tax-periods/{$periodId}/mark-filed")->assertRedirect(route('accounting.tax-periods.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($periodId) {
            $this->assertSame(TaxPeriod::STATUS_FILED, TaxPeriod::query()->find($periodId)->filing_status);
        });
    }

    public function test_store_rejects_an_invalid_company(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/tax-periods', [
            'company_id' => 999999, 'obligation_type' => TaxPeriod::OBLIGATION_PPN, 'masa_pajak' => '2026-01',
        ])->assertSessionHasErrors(['company_id']);
    }

    public function test_mark_filed_rejects_an_already_filed_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = app(TaxPeriodService::class)->ensurePeriod($company->id, TaxPeriod::OBLIGATION_PPH, '2026-01');
            app(TaxPeriodService::class)->markFiled($period);

            $this->expectException(ValidationException::class);
            app(TaxPeriodService::class)->markFiled($period->fresh());
        });
    }

    public function test_pph_obligation_due_date_defaults_to_the_10th_of_the_following_month(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = app(TaxPeriodService::class)->ensurePeriod($company->id, TaxPeriod::OBLIGATION_PPH, '2026-01');
            $this->assertSame('2026-02-10', $period->due_date->toDateString());
        });
    }

    public function test_ensure_period_is_get_or_create_and_is_late_reflects_an_overdue_open_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $service = app(TaxPeriodService::class);

            $first = $service->ensurePeriod($company->id, TaxPeriod::OBLIGATION_PPN, '2020-01');
            $again = $service->ensurePeriod($company->id, TaxPeriod::OBLIGATION_PPN, '2020-01');
            $this->assertSame($first->id, $again->id);

            // A 2020 PPN period's due date is long past and still 'open' -> isLate() true.
            $this->assertTrue($first->isLate());

            $service->markFiled($first);
            $this->assertFalse($first->fresh()->isLate());
        });
    }

    public function test_admin_can_create_and_deactivate_a_faktur_block(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/faktur-blocks?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FakturBlocks/Index'));
        $this->get("/accounting/faktur-blocks/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FakturBlocks/Create'));

        $this->post('/accounting/faktur-blocks', [
            'company_id' => $companyId, 'prefix' => '010.000-26.', 'range_start' => 1, 'range_end' => 100,
        ])->assertRedirect(route('accounting.faktur-blocks.index', ['company_id' => $companyId]));

        $blockId = null;
        $tenant->run(function () use (&$blockId, $companyId) {
            $block = FakturPajakNumberBlock::query()->where('company_id', $companyId)->first();
            $blockId = $block->id;
            // last_issued null -> remaining() falls back to range_start - 1.
            $this->assertSame(100, $block->remaining());
        });

        $this->get("/accounting/faktur-blocks?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('blocks', 1)->where('blocks.0.remaining', 100));

        $tenant->run(function () use ($blockId) {
            $block = FakturPajakNumberBlock::query()->find($blockId);
            $block->update(['last_issued' => 40]);
            $this->assertSame(60, $block->remaining());
        });

        $this->post("/accounting/faktur-blocks/{$blockId}/deactivate")->assertRedirect(route('accounting.faktur-blocks.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($blockId) {
            $this->assertFalse(FakturPajakNumberBlock::query()->find($blockId)->is_active);
        });
    }

    public function test_faktur_block_store_rejects_an_invalid_company_and_an_inverted_range(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/faktur-blocks', [
            'company_id' => 999999, 'prefix' => 'X', 'range_start' => 1, 'range_end' => 100,
        ])->assertSessionHasErrors(['company_id']);

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->expectException(ValidationException::class);
            app(FakturPajakNumberBlockService::class)->create([
                'company_id' => $company->id, 'prefix' => 'X', 'range_start' => 100, 'range_end' => 1,
            ]);
        });
    }
}
