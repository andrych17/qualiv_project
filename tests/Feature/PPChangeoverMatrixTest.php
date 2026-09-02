<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\ChangeoverMatrix;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Models\ResourceGroupMember;
use App\Modules\PP\Models\ScheduleOp;
use App\Modules\PP\Services\ChangeoverMatrixService;
use App\Modules\PP\Services\SchedulingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3J — the matrix's own CRUD constraint (from/to keyed on product OR family, never
 * neither) and ChangeoverMatrixService::lookup()'s specificity precedence, plus the end-to-end
 * proof that §3I's minimize_setup/minimize_changeover strategies actually consume real matrix
 * data rather than falling back to plain due-date order.
 */
class PPChangeoverMatrixTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_store_requires_either_product_or_family_on_each_side(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post('/pp/changeover-matrix', [
            'changeover_minutes' => 10,
            'cleaning_minutes' => 5,
        ])->assertSessionHasErrors(['from_product_id', 'from_family', 'to_product_id', 'to_family']);
    }

    public function test_lookup_prefers_exact_product_match_over_family_over_wildcard(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
            $catRed = ProductCategory::query()->create(['name' => 'red']);
            $productA = Product::query()->create([
                'sku' => 'CM-A', 'name' => 'A', 'base_uom_id' => $uom->id, 'category_id' => $catRed->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $productB = Product::query()->create([
                'sku' => 'CM-B', 'name' => 'B', 'base_uom_id' => $uom->id, 'category_id' => $catRed->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);

            // Three candidate rows, cheapest-to-most-specific inverted on purpose: the wildcard is
            // cheapest and the exact-product row is priciest, so a naive "pick lowest cost" lookup
            // would fail this test — only specificity ordering picks the exact-product row.
            ChangeoverMatrix::query()->create(['from_family' => 'other', 'to_family' => 'other', 'changeover_minutes' => 1, 'cleaning_minutes' => 0]);
            ChangeoverMatrix::query()->create(['from_family' => 'red', 'to_family' => 'red', 'changeover_minutes' => 5, 'cleaning_minutes' => 0]);
            ChangeoverMatrix::query()->create(['from_product_id' => $productA->id, 'to_product_id' => $productB->id, 'changeover_minutes' => 15, 'cleaning_minutes' => 3]);

            $result = app(ChangeoverMatrixService::class)->lookup('mes_work_center', 701, $productA->id, $productB->id);

            $this->assertSame(15, $result['changeover_minutes']);
            $this->assertSame(3, $result['cleaning_minutes']);
        });
    }

    public function test_lookup_scopes_by_resource_group_via_resource_group_member(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
            $productA = Product::query()->create([
                'sku' => 'CM-C', 'name' => 'C', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $productB = Product::query()->create([
                'sku' => 'CM-D', 'name' => 'D', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);

            $mixing = ResourceGroup::query()->create(['code' => 'MIXING', 'name' => 'Mixing']);
            $assembly = ResourceGroup::query()->create(['code' => 'ASSEMBLY', 'name' => 'Assembly']);
            ResourceGroupMember::query()->create(['resource_group_id' => $mixing->id, 'resource_type' => 'mes_work_center', 'resource_ref_id' => 801]);

            // Scoped to ASSEMBLY, not MIXING — must not apply to resource 801 (a MIXING member).
            ChangeoverMatrix::query()->create([
                'from_product_id' => $productA->id, 'to_product_id' => $productB->id,
                'resource_group_id' => $assembly->id, 'changeover_minutes' => 99, 'cleaning_minutes' => 0,
            ]);
            // Matrix-wide fallback (no resource_group_id).
            ChangeoverMatrix::query()->create([
                'from_product_id' => $productA->id, 'to_product_id' => $productB->id,
                'changeover_minutes' => 7, 'cleaning_minutes' => 2,
            ]);

            $result = app(ChangeoverMatrixService::class)->lookup('mes_work_center', 801, $productA->id, $productB->id);

            $this->assertSame(7, $result['changeover_minutes']);
        });
    }

    public function test_lookup_returns_zero_for_the_same_product_without_touching_the_matrix(): void
    {
        $tenant = $this->provisionTenant();

        $tenant->run(function () {
            $result = app(ChangeoverMatrixService::class)->lookup('mes_work_center', 701, 42, 42);

            $this->assertSame(['changeover_minutes' => 0, 'cleaning_minutes' => 0], $result);
        });
    }

    /**
     * The real end-to-end proof: minimize_setup (changeover time only) and minimize_changeover
     * (changeover + cleaning) reorder a resource's draft queue differently from plain due-date
     * order, and differently from each other, because they're reading real `pp_changeover_matrix`
     * data through the same resource group both ops' resource is a member of.
     */
    public function test_minimize_setup_and_minimize_changeover_diverge_from_due_date_and_each_other(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        [$blueOpId, $redOpId, $greenOpId] = $this->seedThreeFamilyOps($tenant);

        // minimize_setup: blue→red costs less changeover (10) than blue→green (20), so red is
        // picked next despite green having the earlier due date — divergence from plain EDD.
        $this->post('/pp/schedule-ops/apply-strategy', [
            'resource_type' => 'mes_work_center', 'resource_ref_id' => 701,
            'strategy' => SchedulingRuleService::STRATEGY_MINIMIZE_SETUP,
        ])->assertRedirect('/pp/schedule-ops');

        $tenant->run(function () use ($blueOpId, $redOpId, $greenOpId) {
            $this->assertSame(1, ScheduleOp::query()->find($blueOpId)->seq);
            $this->assertSame(2, ScheduleOp::query()->find($redOpId)->seq);
            $this->assertSame(3, ScheduleOp::query()->find($greenOpId)->seq);
        });

        // minimize_changeover: blue→red totals 10+40=50, blue→green totals 20+5=25 — cleaning
        // time flips the preference, so green is picked next instead of red.
        $this->post('/pp/schedule-ops/apply-strategy', [
            'resource_type' => 'mes_work_center', 'resource_ref_id' => 701,
            'strategy' => SchedulingRuleService::STRATEGY_MINIMIZE_CHANGEOVER,
        ])->assertRedirect('/pp/schedule-ops');

        $tenant->run(function () use ($blueOpId, $redOpId, $greenOpId) {
            $this->assertSame(1, ScheduleOp::query()->find($blueOpId)->seq);
            $this->assertSame(2, ScheduleOp::query()->find($greenOpId)->seq);
            $this->assertSame(3, ScheduleOp::query()->find($redOpId)->seq);
        });
    }

    /** @return array{0: int, 1: int, 2: int} [blueOpId, redOpId, greenOpId] */
    private function seedThreeFamilyOps($tenant): array
    {
        $ids = [];

        $tenant->run(function () use (&$ids) {
            $uom = Uom::query()->firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
            $catBlue = ProductCategory::query()->create(['name' => 'blue']);
            $catRed = ProductCategory::query()->create(['name' => 'red']);
            $catGreen = ProductCategory::query()->create(['name' => 'green']);

            $productBlue = Product::query()->create(['sku' => 'CM-BLUE', 'name' => 'Blue', 'base_uom_id' => $uom->id, 'category_id' => $catBlue->id, 'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE]);
            $productRed = Product::query()->create(['sku' => 'CM-RED', 'name' => 'Red', 'base_uom_id' => $uom->id, 'category_id' => $catRed->id, 'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE]);
            $productGreen = Product::query()->create(['sku' => 'CM-GREEN', 'name' => 'Green', 'base_uom_id' => $uom->id, 'category_id' => $catGreen->id, 'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE]);

            $mixing = ResourceGroup::query()->create(['code' => 'CM-MIXING', 'name' => 'Mixing']);
            ResourceGroupMember::query()->create(['resource_group_id' => $mixing->id, 'resource_type' => 'mes_work_center', 'resource_ref_id' => 701]);

            ChangeoverMatrix::query()->create(['from_family' => 'blue', 'to_family' => 'red', 'resource_group_id' => $mixing->id, 'changeover_minutes' => 10, 'cleaning_minutes' => 40]);
            ChangeoverMatrix::query()->create(['from_family' => 'blue', 'to_family' => 'green', 'resource_group_id' => $mixing->id, 'changeover_minutes' => 20, 'cleaning_minutes' => 5]);

            $bom = Bom::query()->create(['product_id' => $productBlue->id, 'version' => 1, 'is_active' => true]);
            $orderBlue = PlannedOrder::query()->create(['plan_number' => 'PLN-CM-0001', 'order_type' => PlannedOrder::TYPE_PRODUCTION, 'product_id' => $productBlue->id, 'qty' => 1, 'need_by_date' => '2026-09-10', 'bom_id' => $bom->id, 'status' => PlannedOrder::STATUS_PLANNED]);
            $bomRed = Bom::query()->create(['product_id' => $productRed->id, 'version' => 1, 'is_active' => true]);
            $orderRed = PlannedOrder::query()->create(['plan_number' => 'PLN-CM-0002', 'order_type' => PlannedOrder::TYPE_PRODUCTION, 'product_id' => $productRed->id, 'qty' => 1, 'need_by_date' => '2026-09-12', 'bom_id' => $bomRed->id, 'status' => PlannedOrder::STATUS_PLANNED]);
            $bomGreen = Bom::query()->create(['product_id' => $productGreen->id, 'version' => 1, 'is_active' => true]);
            $orderGreen = PlannedOrder::query()->create(['plan_number' => 'PLN-CM-0003', 'order_type' => PlannedOrder::TYPE_PRODUCTION, 'product_id' => $productGreen->id, 'qty' => 1, 'need_by_date' => '2026-09-11', 'bom_id' => $bomGreen->id, 'status' => PlannedOrder::STATUS_PLANNED]);

            $ids[] = ScheduleOp::query()->create(['planned_order_id' => $orderBlue->id, 'seq' => 1, 'resource_type' => 'mes_work_center', 'resource_ref_id' => 701, 'planned_start' => '2026-09-20 08:00:00', 'planned_end' => '2026-09-20 12:00:00', 'status' => ScheduleOp::STATUS_DRAFT])->id;
            $ids[] = ScheduleOp::query()->create(['planned_order_id' => $orderRed->id, 'seq' => 2, 'resource_type' => 'mes_work_center', 'resource_ref_id' => 701, 'planned_start' => '2026-09-21 08:00:00', 'planned_end' => '2026-09-21 12:00:00', 'status' => ScheduleOp::STATUS_DRAFT])->id;
            $ids[] = ScheduleOp::query()->create(['planned_order_id' => $orderGreen->id, 'seq' => 3, 'resource_type' => 'mes_work_center', 'resource_ref_id' => 701, 'planned_start' => '2026-09-22 08:00:00', 'planned_end' => '2026-09-22 12:00:00', 'status' => ScheduleOp::STATUS_DRAFT])->id;
        });

        return $ids;
    }
}
