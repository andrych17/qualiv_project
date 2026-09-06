<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AssetDisposal;
use App\Modules\Accounting\Models\DepreciationScheduleCommercial;
use App\Modules\Accounting\Models\DepreciationScheduleFiscal;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\Accounting\Services\AssetDisposalService;
use App\Modules\Accounting\Services\DepreciationRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3G — the monthly depreciation batch and asset disposal, sharing DepreciationRunService::runForAssets() as their common core. */
class DepreciationRunAndDisposalTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_run_straight_line_depreciation_for_a_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $assetId, $periodId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$assetId, &$periodId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $assetId = $this->makeFixedAsset($company)->id;
        });

        $this->get("/accounting/depreciation-runs?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/DepreciationRuns/Index')->has('periods', 12));

        $this->post('/accounting/depreciation-runs', ['fiscal_period_id' => $periodId])->assertRedirect(route('accounting.depreciation-runs.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($assetId, $periodId) {
            $row = DepreciationScheduleCommercial::query()->where('asset_id', $assetId)->where('fiscal_period_id', $periodId)->first();
            $this->assertNotNull($row);
            $this->assertEqualsWithDelta(250000.0, (float) $row->depreciation_amount, 0.01);
            $this->assertEqualsWithDelta(250000.0, (float) $row->accumulated_depreciation, 0.01);
            $this->assertEqualsWithDelta(11750000.0, (float) $row->net_book_value, 0.01);
            $this->assertNotNull($row->journal_id);
            $this->assertEqualsWithDelta(250000.0, (float) $row->journal->lines()->sum('debit'), 0.01);

            $fiscalRow = DepreciationScheduleFiscal::query()->where('asset_id', $assetId)->where('fiscal_period_id', $periodId)->first();
            $this->assertNotNull($fiscalRow);
            $this->assertEqualsWithDelta(250000.0, (float) $fiscalRow->depreciation_amount, 0.01);
        });
    }

    public function test_declining_balance_depreciation_computes_against_net_book_value(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$assetId, $periodId] = [null, null];
        $tenant->run(function () use (&$assetId, &$periodId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $assetId = $this->makeFixedAsset($company, [
                'commercial_method' => FixedAsset::METHOD_DECLINING_BALANCE, 'commercial_declining_rate' => 0.5,
                'fiscal_method' => FixedAsset::METHOD_DECLINING_BALANCE,
            ])->id;
        });

        $this->post('/accounting/depreciation-runs', ['fiscal_period_id' => $periodId])->assertRedirect();

        $tenant->run(function () use ($assetId, $periodId) {
            // 12,000,000 * (0.5 / 12) = 500,000 for both commercial (own rate) and fiscal (group's own default 0.5 rate).
            $commercial = DepreciationScheduleCommercial::query()->where('asset_id', $assetId)->where('fiscal_period_id', $periodId)->first();
            $this->assertEqualsWithDelta(500000.0, (float) $commercial->depreciation_amount, 0.01);
            $fiscal = DepreciationScheduleFiscal::query()->where('asset_id', $assetId)->where('fiscal_period_id', $periodId)->first();
            $this->assertEqualsWithDelta(500000.0, (float) $fiscal->depreciation_amount, 0.01);
        });
    }

    public function test_rerunning_a_period_only_picks_up_newly_added_assets(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$company, $periodId, $firstAssetId] = [null, null, null];
        $tenant->run(function () use (&$company, &$periodId, &$firstAssetId) {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $periodId = $period->id;
            $firstAssetId = $this->makeFixedAsset($company)->id;
        });

        $this->post('/accounting/depreciation-runs', ['fiscal_period_id' => $periodId])->assertRedirect();

        $secondAssetId = null;
        $tenant->run(function () use (&$secondAssetId, $company) {
            $secondAssetId = $this->makeFixedAsset($company)->id;
        });

        $this->post('/accounting/depreciation-runs', ['fiscal_period_id' => $periodId])->assertRedirect();

        $tenant->run(function () use ($firstAssetId, $secondAssetId, $periodId) {
            $this->assertSame(1, DepreciationScheduleCommercial::query()->where('asset_id', $firstAssetId)->where('fiscal_period_id', $periodId)->count());
            $this->assertSame(1, DepreciationScheduleCommercial::query()->where('asset_id', $secondAssetId)->where('fiscal_period_id', $periodId)->count());
        });
    }

    public function test_depreciation_self_terminates_once_fully_depreciated(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $asset = $this->makeFixedAsset($company, ['acquisition_cost' => 100, 'commercial_useful_life_months' => 1]);
            $group = $asset->assetGroup;
            $group->update(['fiscal_useful_life_months' => 1]);

            $period1 = $this->firstPeriod($fiscalYear);
            app(DepreciationRunService::class)->runForAssets(collect([$asset]), $period1, $this->adminUserId());

            $period2 = FiscalPeriod::query()->where('fiscal_year_id', $fiscalYear->id)->where('period_no', 2)->firstOrFail();
            $result = app(DepreciationRunService::class)->runForAssets(collect([$asset->fresh()]), $period2, $this->adminUserId());

            // Fully depreciated after period 1 (cost 100 / life 1 = 100) — period 2 computes 0 and adds nothing.
            $this->assertSame(0, $result['commercialCount']);
            $this->assertSame(0, $result['fiscalCount']);
            $this->assertNull($result['journalId']);
        });
    }

    public function test_store_rejects_an_invalid_fiscal_period(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/depreciation-runs', ['fiscal_period_id' => 999999])->assertSessionHasErrors(['fiscal_period_id']);
    }

    public function test_admin_can_dispose_an_asset_at_a_gain(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$assetId, $proceedsAccountId, $gainLossAccountId] = [null, null, null];
        $tenant->run(function () use (&$assetId, &$proceedsAccountId, &$gainLossAccountId) {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $asset = $this->makeFixedAsset($company);
            $assetId = $asset->id;
            $proceedsAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $gainLossAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;
        });

        $this->get("/accounting/fixed-assets/{$assetId}/dispose")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FixedAssets/Dispose'));

        // No depreciation run yet for the disposal period — dispose() always catches up the
        // disposal's own period first (250,000), so NBV at disposal is 11,750,000, not the
        // full 12,000,000 cost. Proceeds of 12,500,000 against that NBV is a 750,000 gain.
        $this->post("/accounting/fixed-assets/{$assetId}/dispose", [
            'disposal_date' => '2026-01-15', 'proceeds' => 12500000,
            'proceeds_gl_account_id' => $proceedsAccountId, 'gain_loss_gl_account_id' => $gainLossAccountId,
        ])->assertRedirect(route('accounting.fixed-assets.show', $assetId));

        $tenant->run(function () use ($assetId) {
            $asset = FixedAsset::query()->find($assetId);
            $this->assertSame(FixedAsset::STATUS_DISPOSED, $asset->status);

            $disposal = AssetDisposal::query()->where('asset_id', $assetId)->first();
            $this->assertEqualsWithDelta(750000.0, (float) $disposal->gain_loss_amount, 0.01);
            $this->assertNotNull($disposal->journal_id);
            $this->assertTrue($disposal->journal->lines()->where('account_id', $asset->asset_gl_account_id)->where('credit', 12000000)->exists());
            $this->assertTrue($disposal->journal->lines()->where('account_id', $asset->accumulated_depreciation_gl_account_id)->where('debit', 250000)->exists());
        });
    }

    public function test_dispose_rejects_invalid_proceeds_and_gain_loss_accounts(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $assetId = null;
        $tenant->run(function () use (&$assetId) {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetId = $this->makeFixedAsset($company)->id;
        });

        $this->post("/accounting/fixed-assets/{$assetId}/dispose", [
            'disposal_date' => '2026-01-15', 'proceeds' => 100,
            'proceeds_gl_account_id' => 999999, 'gain_loss_gl_account_id' => 999999,
        ])->assertSessionHasErrors(['proceeds_gl_account_id', 'gain_loss_gl_account_id']);
    }

    public function test_dispose_catches_up_a_missing_depreciation_period_and_computes_a_loss(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$assetId, $gainLossAccountId] = [null, null];
        $tenant->run(function () use (&$assetId, &$gainLossAccountId) {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetId = $this->makeFixedAsset($company)->id;
            $gainLossAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        // proceeds 0, disposal period never depreciated before this call -> catch-up runs
        // it (250,000), NBV = 11,750,000 -> loss of 11,750,000.
        $this->post("/accounting/fixed-assets/{$assetId}/dispose", [
            'disposal_date' => '2026-01-15', 'proceeds' => 0, 'gain_loss_gl_account_id' => $gainLossAccountId,
        ])->assertRedirect();

        $tenant->run(function () use ($assetId) {
            $this->assertSame(1, DepreciationScheduleCommercial::query()->where('asset_id', $assetId)->count());
            $disposal = AssetDisposal::query()->where('asset_id', $assetId)->first();
            $this->assertEqualsWithDelta(-11750000.0, (float) $disposal->gain_loss_amount, 0.01);
            $this->assertTrue($disposal->journal->lines()->where('account_id', $disposal->gain_loss_gl_account_id)->where('debit', 11750000)->exists());
        });
    }

    public function test_dispose_at_exactly_net_book_value_posts_no_gain_loss_line(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$assetId, $proceedsAccountId, $gainLossAccountId] = [null, null, null];
        $tenant->run(function () use (&$assetId, &$proceedsAccountId, &$gainLossAccountId) {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetId = $this->makeFixedAsset($company)->id;
            $proceedsAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $gainLossAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;
        });

        // The catch-up run depreciates the disposal period first (250,000), so NBV at
        // disposal is 11,750,000 — proceeds set equal to that makes gain_loss == 0 exactly,
        // hitting neither the gain nor the loss branch.
        $this->post("/accounting/fixed-assets/{$assetId}/dispose", [
            'disposal_date' => '2026-01-15', 'proceeds' => 11750000,
            'proceeds_gl_account_id' => $proceedsAccountId, 'gain_loss_gl_account_id' => $gainLossAccountId,
        ])->assertRedirect();

        $tenant->run(function () use ($assetId, $gainLossAccountId) {
            $disposal = AssetDisposal::query()->where('asset_id', $assetId)->first();
            $this->assertEqualsWithDelta(0.0, (float) $disposal->gain_loss_amount, 0.01);
            $this->assertFalse($disposal->journal->lines()->where('account_id', $gainLossAccountId)->exists());
        });
    }

    /** A declining rate small enough to round to 0.00 for this cost means the catch-up run posts nothing — commercialAccumulated stays exactly 0, dropping the accumulated-depreciation line entirely (array_shift branch). */
    public function test_dispose_with_a_negligible_first_period_amount_drops_the_accumulated_depreciation_line(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$assetId, $accDepAccountId, $gainLossAccountId] = [null, null, null];
        $tenant->run(function () use (&$assetId, &$accDepAccountId, &$gainLossAccountId) {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $accDepAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $asset = $this->makeFixedAsset($company, [
                'acquisition_cost' => 100, 'accumulated_depreciation_gl_account_id' => $accDepAccountId,
                'commercial_method' => FixedAsset::METHOD_DECLINING_BALANCE, 'commercial_declining_rate' => 0.0001,
            ]);
            $assetId = $asset->id;
            $gainLossAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE])->id;
        });

        $this->post("/accounting/fixed-assets/{$assetId}/dispose", [
            'disposal_date' => '2026-01-15', 'proceeds' => 0, 'gain_loss_gl_account_id' => $gainLossAccountId,
        ])->assertRedirect();

        $tenant->run(function () use ($assetId, $accDepAccountId) {
            $this->assertSame(0, DepreciationScheduleCommercial::query()->where('asset_id', $assetId)->count());
            $disposal = AssetDisposal::query()->where('asset_id', $assetId)->first();
            // No schedule row was ever created (amount rounded to 0), so latestNbv() falls
            // back to the full acquisition cost — accumulated depreciation is exactly 0.
            $this->assertEqualsWithDelta(100.0, (float) $disposal->commercial_nbv_at_disposal, 0.01);
            $this->assertEqualsWithDelta(-100.0, (float) $disposal->gain_loss_amount, 0.01);
            $this->assertFalse($disposal->journal->lines()->where('account_id', $accDepAccountId)->exists());
        });
    }

    public function test_dispose_rejects_already_disposed_missing_period_and_proceeds_without_account(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$disposedAssetId, $noPeriodAssetId] = [null, null];
        $tenant->run(function () use (&$disposedAssetId, &$noPeriodAssetId) {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $disposedAssetId = $this->makeFixedAsset($company, ['status' => FixedAsset::STATUS_DISPOSED])->id;

            $companyNoPeriod = $this->makeCompany(['legal_name' => 'No Period']);
            $noPeriodAssetId = $this->makeFixedAsset($companyNoPeriod)->id;
        });

        $this->get("/accounting/fixed-assets/{$disposedAssetId}/dispose")->assertSessionHasErrors(['asset']);

        $tenant->run(function () use ($disposedAssetId) {
            $company = FixedAsset::query()->find($disposedAssetId)->company;
            $gainLossAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            try {
                app(AssetDisposalService::class)->dispose(
                    FixedAsset::query()->find($disposedAssetId),
                    ['disposal_date' => '2026-01-15', 'proceeds' => 0, 'gain_loss_gl_account_id' => $gainLossAccount->id],
                    $this->adminUserId(),
                );
                $this->fail('Expected a ValidationException for an already-disposed asset.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('asset', $e->errors());
            }
        });

        $this->post("/accounting/fixed-assets/{$noPeriodAssetId}/dispose", [
            'disposal_date' => '2026-01-15', 'proceeds' => 100,
        ])->assertSessionHasErrors(['gain_loss_gl_account_id']);

        $tenant->run(function () use ($noPeriodAssetId) {
            $company = FixedAsset::query()->find($noPeriodAssetId)->company;
            $gainLossAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_REVENUE]);

            // proceeds > 0 with no proceeds_gl_account_id — FormRequest doesn't check this
            // combination, only the service does.
            try {
                app(AssetDisposalService::class)->dispose(
                    FixedAsset::query()->find($noPeriodAssetId),
                    ['disposal_date' => '2026-01-15', 'proceeds' => 100, 'gain_loss_gl_account_id' => $gainLossAccount->id],
                    $this->adminUserId(),
                );
                $this->fail('Expected a ValidationException for missing proceeds_gl_account_id.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('proceeds_gl_account_id', $e->errors());
            }

            // No fiscal year at all for this company -> no period covers the disposal date.
            try {
                app(AssetDisposalService::class)->dispose(
                    FixedAsset::query()->find($noPeriodAssetId),
                    ['disposal_date' => '2026-01-15', 'proceeds' => 0, 'gain_loss_gl_account_id' => $gainLossAccount->id],
                    $this->adminUserId(),
                );
                $this->fail('Expected a ValidationException for no fiscal period.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('disposal_date', $e->errors());
            }
        });
    }
}
