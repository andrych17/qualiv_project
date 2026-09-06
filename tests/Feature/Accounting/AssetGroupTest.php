<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\AssetGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3G — Indonesian fiscal tax classification (Kelompok 1-4, Bangunan), tenant-editable master data. */
class AssetGroupTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    public function test_admin_can_crud_an_asset_group(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->get("/accounting/asset-groups?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AssetGroups/Index'));
        $this->get("/accounting/asset-groups/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AssetGroups/Create'));

        $this->post('/accounting/asset-groups', [
            'company_id' => $companyId, 'code' => 'KELOMPOK_1', 'name' => 'Kelompok 1', 'is_building' => false,
            'fiscal_useful_life_months' => 48, 'fiscal_straight_line_rate' => 0.25, 'fiscal_declining_rate' => 0.5,
        ])->assertRedirect(route('accounting.asset-groups.index', ['company_id' => $companyId]));

        $groupId = null;
        $tenant->run(function () use (&$groupId, $companyId) {
            $groupId = AssetGroup::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/asset-groups/{$groupId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/AssetGroups/Edit'));

        $this->put("/accounting/asset-groups/{$groupId}", [
            'code' => 'KELOMPOK_1', 'name' => 'Kelompok 1 (renamed)', 'is_building' => false,
            'fiscal_useful_life_months' => 48, 'fiscal_straight_line_rate' => 0.25, 'fiscal_declining_rate' => 0.5, 'is_active' => true,
        ])->assertRedirect(route('accounting.asset-groups.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($groupId) {
            $this->assertSame('Kelompok 1 (renamed)', AssetGroup::query()->find($groupId)->name);
        });

        $this->delete("/accounting/asset-groups/{$groupId}")->assertRedirect(route('accounting.asset-groups.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($groupId) {
            $this->assertNull(AssetGroup::query()->find($groupId));
        });
    }

    public function test_store_rejects_invalid_company_and_a_declining_rate_on_a_building_group(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/asset-groups', [
            'company_id' => 999999, 'code' => 'X', 'name' => 'X',
            'fiscal_useful_life_months' => 48, 'fiscal_straight_line_rate' => 0.25,
        ])->assertSessionHasErrors(['company_id']);

        $tenant->run(function () {
            $companyId = $this->makeCompany()->id;

            $this->post('/accounting/asset-groups', [
                'company_id' => $companyId, 'code' => 'BLD', 'name' => 'Building', 'is_building' => true,
                'fiscal_useful_life_months' => 240, 'fiscal_straight_line_rate' => 0.05, 'fiscal_declining_rate' => 0.1,
            ])->assertSessionHasErrors(['fiscal_declining_rate']);
        });
    }

    public function test_update_rejects_a_declining_rate_on_a_building_group(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $groupId = null;
        $tenant->run(function () use (&$groupId) {
            $company = $this->makeCompany();
            $groupId = $this->makeAssetGroup($company, ['is_building' => true, 'fiscal_declining_rate' => null])->id;
        });

        $this->put("/accounting/asset-groups/{$groupId}", [
            'code' => 'BLD', 'name' => 'Building', 'is_building' => true,
            'fiscal_useful_life_months' => 240, 'fiscal_straight_line_rate' => 0.05, 'fiscal_declining_rate' => 0.1, 'is_active' => true,
        ])->assertSessionHasErrors(['fiscal_declining_rate']);
    }

    public function test_delete_is_blocked_when_the_group_has_assets(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $groupId = null;
        $tenant->run(function () use (&$groupId) {
            $company = $this->makeCompany();
            $group = $this->makeAssetGroup($company);
            $groupId = $group->id;
            $this->makeFixedAsset($company, ['asset_group_id' => $group->id]);
        });

        $this->delete("/accounting/asset-groups/{$groupId}")->assertSessionHasErrors(['asset_group']);
    }

    public function test_admin_can_seed_the_starter_groups_once(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $companyId = null;
        $tenant->run(function () use (&$companyId) {
            $companyId = $this->makeCompany()->id;
        });

        $this->post("/accounting/companies/{$companyId}/seed-starter-asset-groups")->assertRedirect(route('accounting.asset-groups.index', ['company_id' => $companyId]));

        $tenant->run(function () use ($companyId) {
            $this->assertSame(6, AssetGroup::query()->where('company_id', $companyId)->count());
            $this->assertTrue(AssetGroup::query()->where('company_id', $companyId)->where('code', 'BANGUNAN_PERMANEN')->where('is_building', true)->exists());
        });

        $this->post("/accounting/companies/{$companyId}/seed-starter-asset-groups")->assertSessionHasErrors(['asset_group']);
    }
}
