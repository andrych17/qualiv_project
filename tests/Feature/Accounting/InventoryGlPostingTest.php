<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Events\InventoryGoodsIssued;
use App\Modules\Accounting\Events\InventoryGoodsReceived;
use App\Modules\Accounting\Events\InventoryStockAdjusted;
use App\Modules\Accounting\Listeners\PostGoodsIssuedToGl;
use App\Modules\Accounting\Listeners\PostGoodsReceivedToGl;
use App\Modules\Accounting\Listeners\PostStockAdjustmentToGl;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\InventoryGlMapping;
use App\Modules\Accounting\Models\InventoryGlPosting;
use App\Modules\Accounting\Models\InventoryPostingFailure;
use App\Modules\Accounting\Services\InventoryGlMappingService;
use App\Modules\Accounting\Services\InventoryGlPostingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * §3H Inventory GL Posting — financial-side-only interface engine; consumes events
 * Inventory's own Goods Receipt/Issue/Adjustment engines dispatch. Product/ProductCategory/
 * Uom live in the tenant DB (INVENTORY schema), so every makeProduct() call here must run
 * inside $tenant->run(), same as every other tenant-scoped fixture in this suite.
 */
class InventoryGlPostingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    private function makeProduct(array $attrs = []): Product
    {
        $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Piece', 'is_active' => true]);
        $categoryId = array_key_exists('category_id', $attrs)
            ? $attrs['category_id']
            : ProductCategory::query()->firstOrCreate(['name' => $attrs['category_name'] ?? 'General'], ['is_active' => true])->id;

        return Product::query()->create([
            'sku' => $attrs['sku'] ?? 'SKU-1',
            'name' => $attrs['name'] ?? 'Product 1',
            'base_uom_id' => $uom->id,
            'category_id' => $categoryId,
            'costing_method' => Product::COSTING_FIFO,
            'tracking_mode' => Product::TRACKING_NONE,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_crud_a_mapping(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $itemId, $assetAccountId, $cogsAccountId] = [null, null, null, null];
        $tenant->run(function () use (&$companyId, &$itemId, &$assetAccountId, &$cogsAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $cogsAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $itemId = $this->makeProduct()->id;
        });

        $this->get("/accounting/inventory-gl-mappings?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/InventoryGlMappings/Index'));
        $this->get("/accounting/inventory-gl-mappings/create?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/InventoryGlMappings/Create'));
        // No company_id query param — formOptions()'s accounts ternary false branch.
        $this->get('/accounting/inventory-gl-mappings/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('accounts', []));

        $this->post('/accounting/inventory-gl-mappings', [
            'company_id' => $companyId, 'inventory_item_id' => $itemId,
            'inventory_asset_account_id' => $assetAccountId, 'cogs_account_id' => $cogsAccountId,
        ])->assertRedirect(route('accounting.inventory-gl-mappings.index', ['company_id' => $companyId]));

        $mappingId = null;
        $tenant->run(function () use (&$mappingId, $companyId) {
            $mappingId = InventoryGlMapping::query()->where('company_id', $companyId)->value('id');
        });

        $this->get("/accounting/inventory-gl-mappings/{$mappingId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/InventoryGlMappings/Edit'));

        $this->put("/accounting/inventory-gl-mappings/{$mappingId}", [
            'inventory_item_id' => $itemId, 'inventory_asset_account_id' => $assetAccountId, 'cogs_account_id' => $cogsAccountId,
        ])->assertRedirect(route('accounting.inventory-gl-mappings.index', ['company_id' => $companyId]));

        $this->delete("/accounting/inventory-gl-mappings/{$mappingId}")->assertRedirect(route('accounting.inventory-gl-mappings.index', ['company_id' => $companyId]));
        $tenant->run(function () use ($mappingId) {
            $this->assertNull(InventoryGlMapping::query()->find($mappingId));
        });
    }

    public function test_store_upserts_when_the_scope_already_has_a_mapping(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $itemId, $assetAccountId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$itemId, &$assetAccountId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $itemId = $this->makeProduct()->id;

            app(InventoryGlMappingService::class)->create([
                'company_id' => $companyId, 'inventory_item_id' => $itemId, 'inventory_asset_account_id' => $assetAccountId,
            ], $this->adminUserId());
        });

        // Re-creating for the same item scope upserts instead of erroring.
        $this->post('/accounting/inventory-gl-mappings', [
            'company_id' => $companyId, 'inventory_item_id' => $itemId, 'inventory_asset_account_id' => $assetAccountId,
        ])->assertRedirect();

        $tenant->run(function () use ($companyId, $itemId) {
            $this->assertSame(1, InventoryGlMapping::query()->where('company_id', $companyId)->where('inventory_item_id', $itemId)->count());
        });
    }

    public function test_store_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $this->post('/accounting/inventory-gl-mappings', [
            'company_id' => 999999, 'inventory_item_id' => 999999, 'inventory_category_id' => 999999,
            'inventory_asset_account_id' => 999999, 'cogs_account_id' => 999999,
            'grni_account_id' => 999999, 'adjustment_account_id' => 999999,
        ])->assertSessionHasErrors([
            'company_id', 'inventory_item_id', 'inventory_category_id', 'inventory_asset_account_id',
            'cogs_account_id', 'grni_account_id', 'adjustment_account_id',
        ]);
    }

    public function test_update_rejects_invalid_references(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $mappingId = null;
        $tenant->run(function () use (&$mappingId) {
            $company = $this->makeCompany();
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $itemId = $this->makeProduct()->id;
            $mappingId = app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId, 'inventory_asset_account_id' => $assetAccountId,
            ], $this->adminUserId())->id;
        });

        $this->put("/accounting/inventory-gl-mappings/{$mappingId}", [
            'inventory_item_id' => 999999, 'inventory_category_id' => 999999,
            'inventory_asset_account_id' => 999999, 'cogs_account_id' => 999999,
            'grni_account_id' => 999999, 'adjustment_account_id' => 999999,
        ])->assertSessionHasErrors([
            'inventory_item_id', 'inventory_category_id', 'inventory_asset_account_id',
            'cogs_account_id', 'grni_account_id', 'adjustment_account_id',
        ]);
    }

    public function test_store_rejects_setting_both_item_and_category_or_neither(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $assetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $item = $this->makeProduct();
            $category = ProductCategory::query()->firstOrCreate(['name' => 'Cat X'], ['is_active' => true]);

            $this->expectException(ValidationException::class);
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $item->id, 'inventory_category_id' => $category->id,
                'inventory_asset_account_id' => $assetAccount->id,
            ], $this->adminUserId());
        });
    }

    public function test_update_rejects_a_scope_already_claimed_by_a_different_mapping(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$mappingBId, $itemAId, $assetAccountId] = [null, null, null];
        $tenant->run(function () use (&$mappingBId, &$itemAId, &$assetAccountId) {
            $company = $this->makeCompany();
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $itemAId = $this->makeProduct(['sku' => 'A'])->id;
            $itemBId = $this->makeProduct(['sku' => 'B'])->id;

            $service = app(InventoryGlMappingService::class);
            $service->create(['company_id' => $company->id, 'inventory_item_id' => $itemAId, 'inventory_asset_account_id' => $assetAccountId], $this->adminUserId());
            $mappingBId = $service->create(['company_id' => $company->id, 'inventory_item_id' => $itemBId, 'inventory_asset_account_id' => $assetAccountId], $this->adminUserId())->id;
        });

        $this->put("/accounting/inventory-gl-mappings/{$mappingBId}", [
            'inventory_item_id' => $itemAId, 'inventory_asset_account_id' => $assetAccountId,
        ])->assertSessionHasErrors(['inventory_item_id']);
    }

    public function test_goods_received_posts_debit_asset_credit_grni(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$itemId, $assetAccountId, $grniAccountId] = [null, null, null];
        $tenant->run(function () use (&$itemId, &$assetAccountId, &$grniAccountId) {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $grniAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT])->id;
            $itemId = $this->makeProduct()->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccountId, 'grni_account_id' => $grniAccountId,
            ], $this->adminUserId());

            $companyId = $company->id;
            $event = new InventoryGoodsReceived($companyId, $itemId, 10, 5000, 50000, '2026-01-10', 'inventory.stock_ledger', 'GR-1');
            app(PostGoodsReceivedToGl::class)->handle($event);

            $posting = InventoryGlPosting::query()->where('subject_type', 'inventory.stock_ledger')->where('subject_id', 'GR-1')->first();
            $this->assertNotNull($posting);
            $this->assertTrue($posting->journal->lines()->where('account_id', $assetAccountId)->where('debit', 50000)->exists());
            $this->assertTrue($posting->journal->lines()->where('account_id', $grniAccountId)->where('credit', 50000)->exists());

            // Idempotent: replaying the same event is a no-op, no second posting.
            app(PostGoodsReceivedToGl::class)->handle($event);
            $this->assertSame(1, InventoryGlPosting::query()->where('subject_type', 'inventory.stock_ledger')->where('subject_id', 'GR-1')->count());
        });
    }

    public function test_goods_issued_posts_debit_cogs_credit_asset(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $cogsAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $itemId = $this->makeProduct()->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccountId, 'cogs_account_id' => $cogsAccountId,
            ], $this->adminUserId());

            $event = new InventoryGoodsIssued($company->id, $itemId, 5, 5000, 25000, '2026-01-10', 'inventory.stock_ledger', 'GI-1');
            app(PostGoodsIssuedToGl::class)->handle($event);

            $posting = InventoryGlPosting::query()->where('subject_id', 'GI-1')->first();
            $this->assertTrue($posting->journal->lines()->where('account_id', $cogsAccountId)->where('debit', 25000)->exists());
            $this->assertTrue($posting->journal->lines()->where('account_id', $assetAccountId)->where('credit', 25000)->exists());

            // Idempotent: replaying the same event is a no-op, no second posting.
            app(PostGoodsIssuedToGl::class)->handle($event);
            $this->assertSame(1, InventoryGlPosting::query()->where('subject_id', 'GI-1')->count());
        });
    }

    public function test_a_zero_value_goods_issue_is_skipped_silently(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $cogsAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $itemId = $this->makeProduct()->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccountId, 'cogs_account_id' => $cogsAccountId,
            ], $this->adminUserId());

            $event = new InventoryGoodsIssued($company->id, $itemId, 0, 0, 0, '2026-01-10', 'inventory.stock_ledger', 'GI-ZERO');
            app(PostGoodsIssuedToGl::class)->handle($event);

            $this->assertNull(InventoryGlPosting::query()->where('subject_id', 'GI-ZERO')->first());
            $this->assertNull(InventoryPostingFailure::query()->where('subject_id', 'GI-ZERO')->first());
        });
    }

    public function test_stock_adjustment_write_up_and_write_down_post_opposite_sides(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $adjAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $itemId = $this->makeProduct()->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccountId, 'adjustment_account_id' => $adjAccountId,
            ], $this->adminUserId());

            // Write-up: totalValue positive -> debit asset, credit adjustment.
            $writeUp = new InventoryStockAdjusted($company->id, $itemId, 3, 1000, 3000, '2026-01-10', 'inventory.stock_ledger', 'ADJ-UP');
            app(PostStockAdjustmentToGl::class)->handle($writeUp);
            $upPosting = InventoryGlPosting::query()->where('subject_id', 'ADJ-UP')->first();
            $this->assertTrue($upPosting->journal->lines()->where('account_id', $assetAccountId)->where('debit', 3000)->exists());
            $this->assertTrue($upPosting->journal->lines()->where('account_id', $adjAccountId)->where('credit', 3000)->exists());

            // Write-down: totalValue negative -> debit adjustment, credit asset.
            $writeDown = new InventoryStockAdjusted($company->id, $itemId, -2, 1000, -2000, '2026-01-10', 'inventory.stock_ledger', 'ADJ-DOWN');
            app(PostStockAdjustmentToGl::class)->handle($writeDown);
            $downPosting = InventoryGlPosting::query()->where('subject_id', 'ADJ-DOWN')->first();
            $this->assertTrue($downPosting->journal->lines()->where('account_id', $adjAccountId)->where('debit', 2000)->exists());
            $this->assertTrue($downPosting->journal->lines()->where('account_id', $assetAccountId)->where('credit', 2000)->exists());

            // Idempotent: replaying the same event is a no-op, no second posting.
            app(PostStockAdjustmentToGl::class)->handle($writeUp);
            $this->assertSame(1, InventoryGlPosting::query()->where('subject_id', 'ADJ-UP')->count());
        });
    }

    public function test_a_zero_value_stock_adjustment_is_skipped_silently(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $adjAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id;
            $itemId = $this->makeProduct()->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccountId, 'adjustment_account_id' => $adjAccountId,
            ], $this->adminUserId());

            $event = new InventoryStockAdjusted($company->id, $itemId, 0, 0, 0, '2026-01-10', 'inventory.stock_ledger', 'ADJ-ZERO');
            app(PostStockAdjustmentToGl::class)->handle($event);

            $this->assertNull(InventoryGlPosting::query()->where('subject_id', 'ADJ-ZERO')->first());
            $this->assertNull(InventoryPostingFailure::query()->where('subject_id', 'ADJ-ZERO')->first());
        });
    }

    public function test_a_zero_value_movement_is_skipped_silently(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $grniAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY])->id;
            $itemId = $this->makeProduct()->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccountId, 'grni_account_id' => $grniAccountId,
            ], $this->adminUserId());

            $event = new InventoryGoodsReceived($company->id, $itemId, 0, 0, 0, '2026-01-10', 'inventory.stock_ledger', 'GR-ZERO');
            app(PostGoodsReceivedToGl::class)->handle($event);

            $this->assertNull(InventoryGlPosting::query()->where('subject_id', 'GR-ZERO')->first());
            $this->assertNull(InventoryPostingFailure::query()->where('subject_id', 'GR-ZERO')->first());
        });
    }

    public function test_no_mapping_failure_when_the_product_has_no_category_at_all(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $itemId] = [null, null];
        $tenant->run(function () use (&$companyId, &$itemId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company);
            $itemId = $this->makeProduct(['sku' => 'NOCAT', 'category_id' => null])->id;
        });

        $tenant->run(function () use ($companyId, $itemId) {
            app(PostGoodsReceivedToGl::class)->handle(new InventoryGoodsReceived($companyId, $itemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'GR-NOCAT'));
            $this->assertSame('No GL mapping found for this item or its category.', InventoryPostingFailure::query()->where('subject_id', 'GR-NOCAT')->value('reason'));
        });
    }

    public function test_goods_issued_failures_are_queued_for_no_mapping_incomplete_mapping_and_no_period_then_retry_succeeds(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $unmappedItemId, $incompleteItemId, $assetAccountId, $noPeriodCompanyId, $noPeriodItemId] = [null, null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$unmappedItemId, &$incompleteItemId, &$assetAccountId, &$noPeriodCompanyId, &$noPeriodItemId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $unmappedItemId = $this->makeProduct(['sku' => 'GI-UNMAPPED'])->id;
            $incompleteItemId = $this->makeProduct(['sku' => 'GI-INCOMPLETE'])->id;

            // Incomplete mapping: no cogs_account_id, needed for goods-issue.
            app(InventoryGlMappingService::class)->create([
                'company_id' => $companyId, 'inventory_item_id' => $incompleteItemId, 'inventory_asset_account_id' => $assetAccountId,
            ], $this->adminUserId());

            $companyNoPeriod = $this->makeCompany(['legal_name' => 'GI No Period']);
            $noPeriodCompanyId = $companyNoPeriod->id;
            $noPeriodItemId = $this->makeProduct(['sku' => 'GI-NOPERIOD'])->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $noPeriodCompanyId, 'inventory_item_id' => $noPeriodItemId,
                'inventory_asset_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_ASSET])->id,
                'cogs_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_EXPENSE])->id,
            ], $this->adminUserId());
        });

        $tenant->run(function () use ($companyId, $unmappedItemId, $incompleteItemId, $assetAccountId, $noPeriodCompanyId, $noPeriodItemId) {
            app(PostGoodsIssuedToGl::class)->handle(new InventoryGoodsIssued($companyId, $unmappedItemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'GI-NOMAP'));
            app(PostGoodsIssuedToGl::class)->handle(new InventoryGoodsIssued($companyId, $incompleteItemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'GI-INCOMPLETE'));
            app(PostGoodsIssuedToGl::class)->handle(new InventoryGoodsIssued($noPeriodCompanyId, $noPeriodItemId, 1, 100, 100, '2026-06-10', 'inventory.stock_ledger', 'GI-NOPERIOD'));

            $this->assertSame('No GL mapping found for this item or its category.', InventoryPostingFailure::query()->where('subject_id', 'GI-NOMAP')->value('reason'));
            $this->assertSame('Mapping exists but has no COGS account configured for goods issues.', InventoryPostingFailure::query()->where('subject_id', 'GI-INCOMPLETE')->value('reason'));
            $this->assertSame('No fiscal period covers this movement date.', InventoryPostingFailure::query()->where('subject_id', 'GI-NOPERIOD')->value('reason'));

            // Fix the incomplete mapping, then retry() (dispatched for a GOODS_ISSUED failure) succeeds.
            $mapping = InventoryGlMapping::query()->where('company_id', $companyId)->where('inventory_item_id', $incompleteItemId)->first();
            $cogsAccount = $this->makeAccount(Company::find($companyId), ['account_type' => Account::TYPE_EXPENSE]);
            app(InventoryGlMappingService::class)->update($mapping, ['inventory_item_id' => $incompleteItemId, 'inventory_asset_account_id' => $assetAccountId, 'cogs_account_id' => $cogsAccount->id], $this->adminUserId());

            $failure = InventoryPostingFailure::query()->where('subject_id', 'GI-INCOMPLETE')->first();
            app(InventoryGlPostingService::class)->retry($failure);
            $this->assertNotNull(InventoryGlPosting::query()->where('subject_id', 'GI-INCOMPLETE')->first());
        });
    }

    public function test_stock_adjusted_failures_are_queued_for_no_mapping_incomplete_mapping_and_no_period_then_retry_succeeds(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $unmappedItemId, $incompleteItemId, $assetAccountId, $noPeriodCompanyId, $noPeriodItemId] = [null, null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$unmappedItemId, &$incompleteItemId, &$assetAccountId, &$noPeriodCompanyId, &$noPeriodItemId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $unmappedItemId = $this->makeProduct(['sku' => 'ADJ-UNMAPPED'])->id;
            $incompleteItemId = $this->makeProduct(['sku' => 'ADJ-INCOMPLETE'])->id;

            // Incomplete mapping: no adjustment_account_id, needed for stock adjustments.
            app(InventoryGlMappingService::class)->create([
                'company_id' => $companyId, 'inventory_item_id' => $incompleteItemId, 'inventory_asset_account_id' => $assetAccountId,
            ], $this->adminUserId());

            $companyNoPeriod = $this->makeCompany(['legal_name' => 'ADJ No Period']);
            $noPeriodCompanyId = $companyNoPeriod->id;
            $noPeriodItemId = $this->makeProduct(['sku' => 'ADJ-NOPERIOD'])->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $noPeriodCompanyId, 'inventory_item_id' => $noPeriodItemId,
                'inventory_asset_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_ASSET])->id,
                'adjustment_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_EXPENSE])->id,
            ], $this->adminUserId());
        });

        $tenant->run(function () use ($companyId, $unmappedItemId, $incompleteItemId, $assetAccountId, $noPeriodCompanyId, $noPeriodItemId) {
            app(PostStockAdjustmentToGl::class)->handle(new InventoryStockAdjusted($companyId, $unmappedItemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'ADJ-NOMAP'));
            app(PostStockAdjustmentToGl::class)->handle(new InventoryStockAdjusted($companyId, $incompleteItemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'ADJ-INCOMPLETE'));
            app(PostStockAdjustmentToGl::class)->handle(new InventoryStockAdjusted($noPeriodCompanyId, $noPeriodItemId, 1, 100, 100, '2026-06-10', 'inventory.stock_ledger', 'ADJ-NOPERIOD'));

            $this->assertSame('No GL mapping found for this item or its category.', InventoryPostingFailure::query()->where('subject_id', 'ADJ-NOMAP')->value('reason'));
            $this->assertSame('Mapping exists but has no adjustment/write-off account configured.', InventoryPostingFailure::query()->where('subject_id', 'ADJ-INCOMPLETE')->value('reason'));
            $this->assertSame('No fiscal period covers this movement date.', InventoryPostingFailure::query()->where('subject_id', 'ADJ-NOPERIOD')->value('reason'));

            // Fix the incomplete mapping, then retry() (dispatched for a STOCK_ADJUSTED failure) succeeds.
            $mapping = InventoryGlMapping::query()->where('company_id', $companyId)->where('inventory_item_id', $incompleteItemId)->first();
            $adjAccount = $this->makeAccount(Company::find($companyId), ['account_type' => Account::TYPE_EXPENSE]);
            app(InventoryGlMappingService::class)->update($mapping, ['inventory_item_id' => $incompleteItemId, 'inventory_asset_account_id' => $assetAccountId, 'adjustment_account_id' => $adjAccount->id], $this->adminUserId());

            $failure = InventoryPostingFailure::query()->where('subject_id', 'ADJ-INCOMPLETE')->first();
            app(InventoryGlPostingService::class)->retry($failure);
            $this->assertNotNull(InventoryGlPosting::query()->where('subject_id', 'ADJ-INCOMPLETE')->first());
        });
    }

    public function test_failures_are_queued_for_no_mapping_incomplete_mapping_and_no_period_then_retry_succeeds(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $unmappedItemId, $incompleteItemId, $assetAccountId, $noPeriodCompanyId, $noPeriodItemId] = [null, null, null, null, null, null];
        $tenant->run(function () use (&$companyId, &$unmappedItemId, &$incompleteItemId, &$assetAccountId, &$noPeriodCompanyId, &$noPeriodItemId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $this->makeFiscalYear($company);
            $assetAccountId = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET])->id;
            $unmappedItemId = $this->makeProduct(['sku' => 'UNMAPPED'])->id;
            $incompleteItemId = $this->makeProduct(['sku' => 'INCOMPLETE'])->id;

            // Incomplete mapping: no grni_account_id, needed for goods-received.
            app(InventoryGlMappingService::class)->create([
                'company_id' => $companyId, 'inventory_item_id' => $incompleteItemId, 'inventory_asset_account_id' => $assetAccountId,
            ], $this->adminUserId());

            // No-period item: mapping exists and is complete, but no fiscal year covers the movement date.
            $companyNoPeriod = $this->makeCompany(['legal_name' => 'No Period']);
            $noPeriodCompanyId = $companyNoPeriod->id;
            $noPeriodItemId = $this->makeProduct(['sku' => 'NOPERIOD'])->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $noPeriodCompanyId, 'inventory_item_id' => $noPeriodItemId,
                'inventory_asset_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_ASSET])->id,
                'grni_account_id' => $this->makeAccount($companyNoPeriod, ['account_type' => Account::TYPE_LIABILITY])->id,
            ], $this->adminUserId());
        });

        $tenant->run(function () use ($companyId, $unmappedItemId, $incompleteItemId, $noPeriodCompanyId, $noPeriodItemId) {
            app(PostGoodsReceivedToGl::class)->handle(new InventoryGoodsReceived($companyId, $unmappedItemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'GR-NOMAP'));
            app(PostGoodsReceivedToGl::class)->handle(new InventoryGoodsReceived($companyId, $incompleteItemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'GR-INCOMPLETE'));
            app(PostGoodsReceivedToGl::class)->handle(new InventoryGoodsReceived($noPeriodCompanyId, $noPeriodItemId, 1, 100, 100, '2026-06-10', 'inventory.stock_ledger', 'GR-NOPERIOD'));

            $this->assertSame(3, InventoryPostingFailure::query()->where('status', InventoryPostingFailure::STATUS_PENDING)->count());
        });

        $this->get("/accounting/inventory-posting-failures?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Accounting/InventoryPostingFailures/Index')->has('failures', 2));

        // Fix the incomplete mapping, then retry succeeds.
        $failureId = null;
        $tenant->run(function () use (&$failureId, $companyId, $incompleteItemId, $assetAccountId) {
            $failureId = InventoryPostingFailure::query()->where('subject_id', 'GR-INCOMPLETE')->value('id');
            $mapping = InventoryGlMapping::query()->where('company_id', $companyId)->where('inventory_item_id', $incompleteItemId)->first();
            $grniAccount = $this->makeAccount(Company::find($companyId), ['account_type' => Account::TYPE_LIABILITY]);
            app(InventoryGlMappingService::class)->update($mapping, ['inventory_item_id' => $incompleteItemId, 'inventory_asset_account_id' => $assetAccountId, 'grni_account_id' => $grniAccount->id], $this->adminUserId());
        });

        $this->post("/accounting/inventory-posting-failures/{$failureId}/retry")->assertRedirect();

        $tenant->run(function () use ($failureId) {
            $this->assertSame(InventoryPostingFailure::STATUS_RESOLVED, InventoryPostingFailure::query()->find($failureId)->status);
            $this->assertNotNull(InventoryGlPosting::query()->where('subject_id', 'GR-INCOMPLETE')->first());
        });

        // Retrying an already-resolved failure is rejected.
        $this->post("/accounting/inventory-posting-failures/{$failureId}/retry")->assertSessionHasErrors(['failure']);
    }

    public function test_retry_that_still_fails_reports_still_failing(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $failureId] = [null, null];
        $tenant->run(function () use (&$companyId, &$failureId) {
            $companyId = $this->makeCompany()->id;
            $unmappedItemId = $this->makeProduct(['sku' => 'STILLFAIL'])->id;

            app(PostGoodsReceivedToGl::class)->handle(new InventoryGoodsReceived($companyId, $unmappedItemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'GR-STILLFAIL'));
            $failureId = InventoryPostingFailure::query()->where('subject_id', 'GR-STILLFAIL')->value('id');
        });

        $this->post("/accounting/inventory-posting-failures/{$failureId}/retry")->assertSessionHasErrors(['failure']);
    }

    /** A failure row's event_type can only ever be one of the three constants in normal operation — this simulates corrupted/pre-existing bad data reaching retry()'s match default branch. */
    public function test_retry_rejects_an_unknown_event_type(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();

            $failure = InventoryPostingFailure::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'event_type' => 'unknown_event',
                'inventory_item_id' => 1, 'subject_type' => 'inventory.stock_ledger', 'subject_id' => 'UNKNOWN-TYPE',
                'payload' => [
                    'companyId' => $company->id, 'inventoryItemId' => 1, 'quantity' => 1, 'unitCost' => 1,
                    'totalValue' => 1, 'movementDate' => '2026-01-10', 'subjectType' => 'inventory.stock_ledger',
                    'subjectId' => 'UNKNOWN-TYPE', 'memo' => null,
                ],
                'reason' => 'test', 'status' => InventoryPostingFailure::STATUS_PENDING,
            ]);

            $this->expectException(\LogicException::class);
            app(InventoryGlPostingService::class)->retry($failure);
        });
    }
}
