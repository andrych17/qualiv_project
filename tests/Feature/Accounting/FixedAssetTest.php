<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\DepreciationScheduleFiscal;
use App\Modules\Accounting\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3G — the asset register: plain CRUD plus the commercial/fiscal method-and-rate guards. */
class FixedAssetTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_an_asset(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $groupId, $assetAccountId, $accDepAccountId, $depExpAccountId] = [null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$groupId, &$assetAccountId, &$accDepAccountId, &$depExpAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $groupId = $this->makeAssetGroup($company)->id;
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $accDepAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $depExpAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
        });

        $this->get("/accounting/fixed-assets?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FixedAssets/Index'));
        $this->get("/accounting/fixed-assets/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FixedAssets/Create'));
        // No company_id query param — groupOptions()/accountOptions()'s early-return branches.
        $this->get('/accounting/fixed-assets/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('assetGroups', [])->where('accounts', []));

        $this->post('/accounting/fixed-assets', [
            'company_id' => $companyId, 'asset_group_id' => $groupId, 'asset_no' => 'FA-001', 'name' => 'Company Car',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 240000000,
            'asset_gl_account_id' => $assetAccountId, 'accumulated_depreciation_gl_account_id' => $accDepAccountId,
            'depreciation_expense_gl_account_id' => $depExpAccountId,
            'commercial_useful_life_months' => 48, 'commercial_method' => FixedAsset::METHOD_STRAIGHT_LINE,
            'fiscal_method' => FixedAsset::METHOD_STRAIGHT_LINE,
        ])->assertRedirect(route('accounting.fixed-assets.index', ['company_id' => $companyId]));

        $assetId = null;
        $tenant->run(function () use (&$assetId, $companyId) {
            $assetId = FixedAsset::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/fixed-assets/{$assetId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FixedAssets/Show')->where('asset.status', FixedAsset::STATUS_ACTIVE));

        $this->get("/accounting/fixed-assets/{$assetId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/FixedAssets/Edit'));

        $this->put("/accounting/fixed-assets/{$assetId}", [
            'asset_group_id' => $groupId, 'asset_no' => 'FA-001', 'name' => 'Company Car (renamed)',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 240000000,
            'asset_gl_account_id' => $assetAccountId, 'accumulated_depreciation_gl_account_id' => $accDepAccountId,
            'depreciation_expense_gl_account_id' => $depExpAccountId,
            'commercial_useful_life_months' => 48, 'commercial_method' => FixedAsset::METHOD_STRAIGHT_LINE,
            'fiscal_method' => FixedAsset::METHOD_STRAIGHT_LINE,
        ])->assertRedirect(route('accounting.fixed-assets.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($assetId) {
            $this->assertSame('Company Car (renamed)', FixedAsset::query()->find($assetId)->name);
        });

        $this->delete("/accounting/fixed-assets/{$assetId}")->assertRedirect(route('accounting.fixed-assets.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($assetId) {
            $this->assertNull(FixedAsset::query()->find($assetId));
        });
    }

    public function test_store_rejects_invalid_references_and_a_missing_declining_rate(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $groupId, $accountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$groupId, &$accountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $groupId = $this->makeAssetGroup($company)->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
        });

        $this->post('/accounting/fixed-assets', [
            'company_id' => $companyId, 'asset_group_id' => 999999, 'asset_no' => 'X', 'name' => 'X',
            'vendor_partner_id' => 999999, 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100,
            'asset_gl_account_id' => 999999, 'accumulated_depreciation_gl_account_id' => 999999, 'depreciation_expense_gl_account_id' => 999999,
            'commercial_useful_life_months' => 48, 'commercial_method' => FixedAsset::METHOD_STRAIGHT_LINE,
            'fiscal_method' => FixedAsset::METHOD_STRAIGHT_LINE,
        ])->assertSessionHasErrors([
            'asset_group_id', 'vendor_partner_id', 'asset_gl_account_id',
            'accumulated_depreciation_gl_account_id', 'depreciation_expense_gl_account_id',
        ]);

        $this->post('/accounting/fixed-assets', [
            'company_id' => 999999, 'asset_group_id' => $groupId, 'asset_no' => 'X', 'name' => 'X',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100,
            'asset_gl_account_id' => $accountId, 'accumulated_depreciation_gl_account_id' => $accountId, 'depreciation_expense_gl_account_id' => $accountId,
            'commercial_useful_life_months' => 48, 'commercial_method' => FixedAsset::METHOD_STRAIGHT_LINE,
            'fiscal_method' => FixedAsset::METHOD_STRAIGHT_LINE,
        ])->assertSessionHasErrors(['company_id']);

        // FormRequest itself has no declining-rate-required check — this is the SERVICE
        // layer's own guard (assertMethodRatesValid), reachable via HTTP since the request
        // still passes validated() straight into the service.
        $this->post('/accounting/fixed-assets', [
            'company_id' => $companyId, 'asset_group_id' => $groupId, 'asset_no' => 'X', 'name' => 'X',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100,
            'asset_gl_account_id' => $accountId, 'accumulated_depreciation_gl_account_id' => $accountId, 'depreciation_expense_gl_account_id' => $accountId,
            'commercial_useful_life_months' => 48, 'commercial_method' => FixedAsset::METHOD_DECLINING_BALANCE,
            'fiscal_method' => FixedAsset::METHOD_STRAIGHT_LINE,
        ])->assertSessionHasErrors(['commercial_declining_rate']);
    }

    public function test_store_rejects_declining_fiscal_method_on_a_building_group_asset(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $buildingGroup = $this->makeAssetGroup($company, ['is_building' => true, 'fiscal_declining_rate' => null]);
            $account = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);

            $this->post('/accounting/fixed-assets', [
                'company_id' => $company->id, 'asset_group_id' => $buildingGroup->id, 'asset_no' => 'BLD-1', 'name' => 'Warehouse',
                'acquisition_date' => '2026-01-01', 'acquisition_cost' => 1000000000,
                'asset_gl_account_id' => $account->id, 'accumulated_depreciation_gl_account_id' => $account->id, 'depreciation_expense_gl_account_id' => $account->id,
                'commercial_useful_life_months' => 240, 'commercial_method' => FixedAsset::METHOD_STRAIGHT_LINE,
                'fiscal_method' => FixedAsset::METHOD_DECLINING_BALANCE,
            ])->assertSessionHasErrors(['fiscal_method']);
        });
    }

    public function test_update_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$assetId, $groupId, $accountId] = [null, null, null];
        $tenant->run(function () use (&$assetId, &$groupId, &$accountId) {
            $company = $this->makeCompany();
            $groupId = $this->makeAssetGroup($company)->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $assetId = $this->makeFixedAsset($company)->id;
        });

        $this->put("/accounting/fixed-assets/{$assetId}", [
            'asset_group_id' => 999999, 'asset_no' => 'X', 'name' => 'X',
            'vendor_partner_id' => 999999, 'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100,
            'asset_gl_account_id' => 999999, 'accumulated_depreciation_gl_account_id' => 999999, 'depreciation_expense_gl_account_id' => 999999,
            'commercial_useful_life_months' => 48, 'commercial_method' => FixedAsset::METHOD_STRAIGHT_LINE,
            'fiscal_method' => FixedAsset::METHOD_STRAIGHT_LINE,
        ])->assertSessionHasErrors([
            'asset_group_id', 'vendor_partner_id', 'asset_gl_account_id',
            'accumulated_depreciation_gl_account_id', 'depreciation_expense_gl_account_id',
        ]);
    }

    public function test_update_and_delete_are_blocked_once_disposed(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$assetId, $groupId, $accountId] = [null, null, null];
        $tenant->run(function () use (&$assetId, &$groupId, &$accountId) {
            $company = $this->makeCompany();
            $group = $this->makeAssetGroup($company);
            $groupId = $group->id;
            $accountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $assetId = $this->makeFixedAsset($company, ['status' => FixedAsset::STATUS_DISPOSED])->id;
        });

        $this->put("/accounting/fixed-assets/{$assetId}", [
            'asset_group_id' => $groupId, 'asset_no' => 'X', 'name' => 'X',
            'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100,
            'asset_gl_account_id' => $accountId, 'accumulated_depreciation_gl_account_id' => $accountId, 'depreciation_expense_gl_account_id' => $accountId,
            'commercial_useful_life_months' => 48, 'commercial_method' => FixedAsset::METHOD_STRAIGHT_LINE,
            'fiscal_method' => FixedAsset::METHOD_STRAIGHT_LINE,
        ])->assertSessionHasErrors(['asset']);

        $this->delete("/accounting/fixed-assets/{$assetId}")->assertSessionHasErrors(['asset']);
    }

    public function test_delete_is_blocked_once_depreciation_history_exists(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $assetId = null;
        $tenant->run(function () use (&$assetId) {
            $company = $this->makeCompany();
            $asset = $this->makeFixedAsset($company);
            $assetId = $asset->id;
            $period = $this->firstPeriod($this->makeFiscalYear($company));

            DepreciationScheduleFiscal::query()->create([
                'asset_id' => $asset->id, 'fiscal_period_id' => $period->id,
                'depreciation_amount' => 250000, 'accumulated_depreciation' => 250000, 'net_book_value' => 11750000,
            ]);
        });

        $this->delete("/accounting/fixed-assets/{$assetId}")->assertSessionHasErrors(['asset']);
    }

    public function test_index_and_show_page_content(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $assetId] = [null, null];
        $tenant->run(function () use (&$companyId, &$assetId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $partner = $this->makePartner();
            $asset = $this->makeFixedAsset($company, ['asset_no' => 'FA-INDEX', 'vendor_partner_id' => $partner->id]);
            $assetId = $asset->id;
        });

        $this->get("/accounting/fixed-assets?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('assets', 1)->where('assets.0.asset_no', 'FA-INDEX'));

        $this->get("/accounting/fixed-assets/{$assetId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('asset.status', FixedAsset::STATUS_ACTIVE)
                ->has('commercialSchedule', 0)
                ->has('fiscalSchedule', 0)
                ->where('disposal', null));
    }
}
