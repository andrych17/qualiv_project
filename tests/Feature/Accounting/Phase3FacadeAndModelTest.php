<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Events\InventoryGoodsReceived;
use App\Modules\Accounting\Events\PayrollRunPaid;
use App\Modules\Accounting\Listeners\PostGoodsReceivedToGl;
use App\Modules\Accounting\Listeners\PostPayrollRunToGl;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\DepreciationScheduleCommercial;
use App\Modules\Accounting\Models\DepreciationScheduleFiscal;
use App\Modules\Accounting\Models\InventoryGlMapping;
use App\Modules\Accounting\Models\InventoryGlPosting;
use App\Modules\Accounting\Models\InventoryPostingFailure;
use App\Modules\Accounting\Models\PayrollComponentGlMapping;
use App\Modules\Accounting\Models\PayrollGlPosting;
use App\Modules\Accounting\Models\PayrollPostingFailure;
use App\Modules\Accounting\Services\AllocationRuleService;
use App\Modules\Accounting\Services\AllocationRunService;
use App\Modules\Accounting\Services\AssetDisposalService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\FixedAssetService;
use App\Modules\Accounting\Services\InventoryGlMappingService;
use App\Modules\Accounting\Services\JournalService;
use App\Modules\Accounting\Services\PayrollComponentGlMappingService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SetsUpAccounting;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** Inverse/side relations no Phase-3 controller's own eager-load already touches — mirrors the Phase 1/2 FacadeAndModelTest files. */
class Phase3FacadeAndModelTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpAccounting;
    use SetsUpTenant;

    private function makeProduct(array $attrs = []): Product
    {
        $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Piece', 'is_active' => true]);
        $categoryId = array_key_exists('category_id', $attrs)
            ? $attrs['category_id']
            : ProductCategory::query()->firstOrCreate(['name' => 'General'], ['is_active' => true])->id;

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

    public function test_fixed_asset_and_disposal_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $group = $this->makeAssetGroup($company);
            $this->makeFiscalYear($company);

            $asset = app(FixedAssetService::class)->create([
                'company_id' => $company->id, 'asset_group_id' => $group->id, 'asset_no' => 'FA-CB', 'name' => 'X',
                'acquisition_date' => '2026-01-01', 'acquisition_cost' => 100,
                'asset_gl_account_id' => $this->makeAccount($company)->id,
                'accumulated_depreciation_gl_account_id' => $this->makeAccount($company)->id,
                'depreciation_expense_gl_account_id' => $this->makeAccount($company)->id,
                'commercial_useful_life_months' => 12, 'commercial_method' => 'straight_line', 'fiscal_method' => 'straight_line',
            ], $this->adminUserId());

            $this->assertSame($company->id, $group->company->id);
            $this->assertTrue($group->assets->contains('id', $asset->id));
            $this->assertSame($this->adminUserId(), $asset->createdBy->id);

            $disposal = app(AssetDisposalService::class)->dispose($asset, [
                'disposal_date' => '2026-01-15', 'proceeds' => 0,
                'gain_loss_gl_account_id' => $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE])->id,
            ], $this->adminUserId());

            $this->assertSame($asset->id, $disposal->asset->id);
            $this->assertSame($this->adminUserId(), $disposal->createdBy->id);

            $commercialRow = DepreciationScheduleCommercial::query()->where('asset_id', $asset->id)->first();
            $this->assertSame($asset->id, $commercialRow->asset->id);
            $fiscalRow = DepreciationScheduleFiscal::query()->where('asset_id', $asset->id)->first();
            $this->assertSame($asset->id, $fiscalRow->asset->id);
        });
    }

    public function test_budget_and_budget_line_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $fiscalYear = $this->makeFiscalYear($company);
            $period = $this->firstPeriod($fiscalYear);
            $account = $this->makeAccount($company);
            $costCenter = $this->makeCostCenter($company);

            $budget = app(BudgetService::class)->getOrCreate($company, $fiscalYear, $this->adminUserId());
            app(BudgetService::class)->saveGrid($budget, $costCenter->id, [['account_id' => $account->id, 'fiscal_period_id' => $period->id, 'amount' => 1000]], $this->adminUserId());

            $this->assertSame($company->id, $budget->company->id);
            $line = $budget->lines->first();
            $this->assertNotNull($line);
            $this->assertSame($budget->id, $line->budget->id);
            $this->assertSame($account->id, $line->account->id);
            $this->assertSame($costCenter->id, $line->costCenter->id);
            $this->assertSame($period->id, $line->fiscalPeriod->id);
        });
    }

    public function test_allocation_rule_target_and_run_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $period = $this->firstPeriod($this->makeFiscalYear($company));
            $sourceAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT]);
            $costCenter = $this->makeCostCenter($company);
            $offsetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT]);

            $rule = app(AllocationRuleService::class)->create(
                ['company_id' => $company->id, 'name' => 'X', 'source_account_id' => $sourceAccount->id],
                [['cost_center_id' => $costCenter->id, 'percentage' => 100]],
                $this->adminUserId(),
            );
            $this->assertSame($rule->id, $rule->targets->first()->rule->id);

            $journal = $this->makeJournal($company, $period, ['debit_account' => $sourceAccount, 'credit_account' => $offsetAccount, 'amount' => 100000]);
            app(JournalService::class)->post($journal, $this->adminUserId());

            $run = app(AllocationRunService::class)->run($rule, $period, $this->adminUserId());
            $this->assertSame($rule->id, $run->rule->id);
            $this->assertSame($this->adminUserId(), $run->createdBy->id);
        });
    }

    public function test_inventory_gl_mapping_side_relations_via_index_page(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $itemId, $itemMappingId] = [null, null, null];
        $tenant->run(function () use (&$companyId, &$itemId, &$itemMappingId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $itemId = $this->makeProduct()->id;
            $categoryId = ProductCategory::query()->firstOrCreate(['name' => 'Category-Scoped'], ['is_active' => true])->id;
            $assetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $cogsAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $grniAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);
            $adjAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);

            $itemMappingId = app(InventoryGlMappingService::class)->create([
                'company_id' => $companyId, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccount->id, 'cogs_account_id' => $cogsAccount->id,
                'grni_account_id' => $grniAccount->id, 'adjustment_account_id' => $adjAccount->id,
            ], $this->adminUserId())->id;

            // Category-scoped mapping — the controller's other scope-label branch.
            app(InventoryGlMappingService::class)->create([
                'company_id' => $companyId, 'inventory_category_id' => $categoryId,
                'inventory_asset_account_id' => $assetAccount->id,
            ], $this->adminUserId());
        });

        $this->get("/accounting/inventory-gl-mappings?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('mappings', 2)
                ->where('mappings.0.scope_label', fn ($v) => str_contains($v, 'Item:'))
                ->where('mappings.1.scope_label', fn ($v) => str_contains($v, 'Category:')));

        $tenant->run(function () use ($itemMappingId, $companyId) {
            $this->assertSame($companyId, InventoryGlMapping::query()->find($itemMappingId)->company->id);
        });
    }

    public function test_inventory_gl_posting_and_failure_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $assetAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_ASSET]);
            $grniAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);
            $itemId = $this->makeProduct()->id;
            app(InventoryGlMappingService::class)->create([
                'company_id' => $company->id, 'inventory_item_id' => $itemId,
                'inventory_asset_account_id' => $assetAccount->id, 'grni_account_id' => $grniAccount->id,
            ], $this->adminUserId());

            $event = new InventoryGoodsReceived($company->id, $itemId, 1, 100, 100, '2026-01-10', 'inventory.stock_ledger', 'GR-REL');
            app(PostGoodsReceivedToGl::class)->handle($event);

            $posting = InventoryGlPosting::query()->where('subject_id', 'GR-REL')->first();
            $this->assertSame($company->id, $posting->company->id);

            $failure = InventoryPostingFailure::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'event_type' => 'goods_received',
                'inventory_item_id' => 1, 'subject_type' => 'inventory.stock_ledger', 'subject_id' => 'FAIL-1',
                'payload' => [], 'reason' => 'test', 'status' => InventoryPostingFailure::STATUS_PENDING,
            ]);
            $this->assertSame($company->id, $failure->company->id);
            $this->assertNull($failure->resolvedBy);
        });
    }

    public function test_payroll_component_gl_mapping_side_relations_via_index_page(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        [$companyId, $mappingId] = [null, null];
        $tenant->run(function () use (&$companyId, &$mappingId) {
            $company = $this->makeCompany();
            $companyId = $company->id;
            $glAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $payableAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);

            $mappingId = app(PayrollComponentGlMappingService::class)->create([
                'company_id' => $companyId, 'component_code' => 'BPJS_ER', 'component_label' => 'Employer BPJS',
                'component_type' => PayrollComponentGlMapping::TYPE_EMPLOYER_COST, 'gl_account_id' => $glAccount->id, 'payable_account_id' => $payableAccount->id,
            ], $this->adminUserId())->id;
        });

        $this->get("/accounting/payroll-component-gl-mappings?company_id={$companyId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('mappings', 1)->where('mappings.0.payable_account', fn ($v) => $v !== null));

        $tenant->run(function () use ($mappingId, $companyId) {
            $this->assertSame($companyId, PayrollComponentGlMapping::query()->find($mappingId)->company->id);
        });
    }

    public function test_payroll_gl_posting_and_failure_side_relations(): void
    {
        $tenant = $this->loginAsAccountingAdmin();

        $tenant->run(function () {
            $company = $this->makeCompany();
            $this->makeFiscalYear($company);
            $salaryAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_EXPENSE]);
            $netPayAccount = $this->makeAccount($company, ['account_type' => Account::TYPE_LIABILITY]);
            $company->update(['payroll_net_pay_payable_account_id' => $netPayAccount->id]);
            app(PayrollComponentGlMappingService::class)->create(['company_id' => $company->id, 'component_code' => 'BASIC', 'component_label' => 'Basic', 'component_type' => PayrollComponentGlMapping::TYPE_EARNING, 'gl_account_id' => $salaryAccount->id], $this->adminUserId());

            $event = new PayrollRunPaid($company->id, '2026-01-25', [['component_code' => 'BASIC', 'amount' => 1000000]], 'payroll.payroll_runs', 'RUN-REL');
            app(PostPayrollRunToGl::class)->handle($event);

            $posting = PayrollGlPosting::query()->where('subject_id', 'RUN-REL')->first();
            $this->assertSame($company->id, $posting->company->id);

            $failure = PayrollPostingFailure::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => $company->id,
                'subject_type' => 'payroll.payroll_runs', 'subject_id' => 'FAIL-1',
                'payload' => [], 'reason' => 'test', 'status' => PayrollPostingFailure::STATUS_PENDING,
            ]);
            $this->assertSame($company->id, $failure->company->id);
            $this->assertNull($failure->resolvedBy);
        });
    }
}
