<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\Recipe;
use App\Modules\PP\Models\RecipeIngredient;
use App\Modules\PP\Models\ScheduleOp;
use App\Modules\PP\Services\SchedulingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3K — batch-size/yield planning (MrpService::applyBatchSizing() +
 * RecipeService::scale()'s waste factor) and the campaign-scheduling scenario the section names
 * verbatim ("White → White → Yellow → Yellow → Dark"), which §3I's STRATEGY_CAMPAIGN already
 * implements generically — this locks in that it produces exactly that grouping for process
 * orders. Tank/utility capacity (§3K's third bullet) is already covered by PPCapacityPlanTest.
 */
class PPProcessManufacturingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_mrp_batch_sizes_and_yield_adjusts_recipe_driven_planned_order(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $fgId = null;
        $rmId = null;
        $tenant->run(function () use (&$fgId, &$rmId) {
            $uom = Uom::query()->create(['code' => 'L', 'name' => 'Liters']);
            $fg = Product::query()->create([
                'sku' => 'PM-FG-01', 'name' => 'Process Finished Good', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $rm = Product::query()->create([
                'sku' => 'PM-RM-01', 'name' => 'Process Raw Material', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $fgId = $fg->id;
            $rmId = $rm->id;

            // batch_size=100, yield=80% (a 100-unit batch only yields 80 good units). waste=10%
            // is set on purpose but expected to have NO effect below — §3D pins
            // RecipeService::scale()'s formula without a waste term, and §3K's batch-size-planning
            // bullet names only batch_size/expected_yield_pct as MRP inputs; expected_waste_pct
            // stays an unread header field until a later spec revision defines what consumes it.
            $recipe = Recipe::query()->create([
                'product_id' => $fg->id, 'version' => 1, 'batch_size' => 100,
                'expected_yield_pct' => 80, 'expected_waste_pct' => 10, 'is_active' => true,
            ]);
            RecipeIngredient::query()->create(['recipe_id' => $recipe->id, 'raw_material_product_id' => $rm->id, 'qty_per_batch' => 10]);
        });

        // Net requirement 150 good units. Gross-for-yield = 150 / 0.8 = 187.5, rounded up to the
        // nearest whole 100-unit batch = 200 — a process order can only run whole batches.
        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $fgId, 'need_by_date' => now()->addDays(7)->toDateString(), 'qty' => 150]],
        ])->assertRedirect('/pp/demand');

        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $assert = function () use ($fgId, $rmId) {
            $fgOrder = PlannedOrder::query()->where('product_id', $fgId)->first();
            $this->assertNotNull($fgOrder);
            $this->assertSame(PlannedOrder::TYPE_PRODUCTION, $fgOrder->order_type);
            $this->assertNotNull($fgOrder->recipe_id);
            $this->assertEqualsWithDelta(200.0, (float) $fgOrder->qty, 0.0001);

            // Dependent ingredient demand is scaled against the batch-rounded 200, per §3D's pinned
            // formula only: 10 per 100-unit batch * (200/100) = 20 — expected_waste_pct=10 above
            // does not change this.
            $rmLine = DemandLine::query()
                ->where('product_id', $rmId)
                ->whereHas('header', fn ($q) => $q->where('source_type', DemandHeader::SOURCE_DEPENDENT))
                ->first();
            $this->assertNotNull($rmLine);
            $this->assertEqualsWithDelta(20.0, (float) $rmLine->qty, 0.0001);
        };

        $tenant->run($assert);

        // Regenerative re-run must reproduce the same batch-rounded qty, not ratchet it upward —
        // MrpService's own idempotency contract (see its class docblock), now exercised through
        // applyBatchSizing()'s ceil() rather than just the BOM path PPMrpEngineTest covers.
        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');
        $tenant->run($assert);
        $tenant->run(function () {
            $this->assertSame(1, DemandHeader::query()->where('source_type', DemandHeader::SOURCE_DEPENDENT)->count());
        });
    }

    public function test_campaign_strategy_groups_process_orders_white_white_yellow_yellow_dark(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $opIds = [];
        $tenant->run(function () use (&$opIds) {
            $uom = Uom::query()->create(['code' => 'L', 'name' => 'Liters']);
            $make = function (string $sku) use ($uom) {
                return Product::query()->create([
                    'sku' => $sku, 'name' => $sku, 'base_uom_id' => $uom->id,
                    'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
                ]);
            };

            // Created in this order so recipe_id ascends White < Yellow < Dark, matching the
            // spec's own grouping order.
            $recipeWhite = Recipe::query()->create(['product_id' => $make('PM-WHITE')->id, 'version' => 1, 'batch_size' => 100, 'is_active' => true]);
            $recipeYellow = Recipe::query()->create(['product_id' => $make('PM-YELLOW')->id, 'version' => 1, 'batch_size' => 100, 'is_active' => true]);
            $recipeDark = Recipe::query()->create(['product_id' => $make('PM-DARK')->id, 'version' => 1, 'batch_size' => 100, 'is_active' => true]);

            $order = fn (string $plan, Recipe $recipe) => PlannedOrder::query()->create([
                'plan_number' => $plan, 'order_type' => PlannedOrder::TYPE_PRODUCTION,
                'product_id' => $recipe->product_id, 'qty' => 100, 'need_by_date' => now()->addDays(10),
                'recipe_id' => $recipe->id, 'status' => PlannedOrder::STATUS_PLANNED,
            ]);

            $orderWhite1 = $order('PLN-PM-0001', $recipeWhite);
            $orderYellow1 = $order('PLN-PM-0002', $recipeYellow);
            $orderWhite2 = $order('PLN-PM-0003', $recipeWhite);
            $orderDark1 = $order('PLN-PM-0004', $recipeDark);
            $orderYellow2 = $order('PLN-PM-0005', $recipeYellow);

            // Interleaved start times — the opposite of the campaign grouping — so a plain
            // chronological/earliest-start order would NOT match, proving campaign actually
            // re-groups rather than coincidentally matching input order.
            $op = fn (PlannedOrder $order, string $start, string $end) => ScheduleOp::query()->create([
                'planned_order_id' => $order->id, 'seq' => 1,
                'resource_type' => 'mes_work_center', 'resource_ref_id' => 901,
                'planned_start' => $start, 'planned_end' => $end, 'status' => ScheduleOp::STATUS_DRAFT,
            ])->id;

            $opIds['white1'] = $op($orderWhite1, '2026-09-20 08:00:00', '2026-09-20 10:00:00');
            $opIds['yellow1'] = $op($orderYellow1, '2026-09-20 10:00:00', '2026-09-20 12:00:00');
            $opIds['white2'] = $op($orderWhite2, '2026-09-20 12:00:00', '2026-09-20 14:00:00');
            $opIds['dark1'] = $op($orderDark1, '2026-09-20 14:00:00', '2026-09-20 16:00:00');
            $opIds['yellow2'] = $op($orderYellow2, '2026-09-20 16:00:00', '2026-09-20 18:00:00');
        });

        $this->post('/pp/schedule-ops/apply-strategy', [
            'resource_type' => 'mes_work_center', 'resource_ref_id' => 901,
            'strategy' => SchedulingRuleService::STRATEGY_CAMPAIGN,
        ])->assertRedirect('/pp/schedule-ops');

        $tenant->run(function () use ($opIds) {
            // White, White, Yellow, Yellow, Dark — grouped by recipe, chronological within group.
            $this->assertSame(1, ScheduleOp::query()->find($opIds['white1'])->seq);
            $this->assertSame(2, ScheduleOp::query()->find($opIds['white2'])->seq);
            $this->assertSame(3, ScheduleOp::query()->find($opIds['yellow1'])->seq);
            $this->assertSame(4, ScheduleOp::query()->find($opIds['yellow2'])->seq);
            $this->assertSame(5, ScheduleOp::query()->find($opIds['dark1'])->seq);
        });
    }
}
