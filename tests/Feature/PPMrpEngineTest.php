<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\BomLine;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3D — MRP engine: BOM explosion into dependent demand, regenerative re-runs
 * (no duplicate accumulation), and scheduled-receipts netting against a released purchase
 * planned order (advisor-flagged correctness case).
 */
class PPMrpEngineTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_mrp_explodes_bom_and_is_idempotent_across_reruns(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $fgId = null;
        $rmId = null;
        $tenant->run(function () use (&$fgId, &$rmId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $fg = Product::query()->create([
                'sku' => 'MRP-FG-01', 'name' => 'Finished Good', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $rm = Product::query()->create([
                'sku' => 'MRP-RM-01', 'name' => 'Raw Material', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $fgId = $fg->id;
            $rmId = $rm->id;

            $bom = Bom::query()->create(['product_id' => $fg->id, 'version' => 1, 'is_active' => true]);
            BomLine::query()->create(['bom_id' => $bom->id, 'component_product_id' => $rm->id, 'qty_per_parent_unit' => 2, 'scrap_pct' => 0]);
        });

        // Manual demand: 10 units of the finished good.
        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $fgId, 'need_by_date' => now()->addDays(7)->toDateString(), 'qty' => 10]],
        ])->assertRedirect('/pp/demand');

        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $tenant->run(function () use ($fgId, $rmId) {
            $this->assertSame(2, PlannedOrder::query()->count());

            $fgOrder = PlannedOrder::query()->where('product_id', $fgId)->first();
            $this->assertNotNull($fgOrder);
            $this->assertSame(PlannedOrder::TYPE_PRODUCTION, $fgOrder->order_type);
            $this->assertEquals(10, $fgOrder->qty);
            $this->assertNotNull($fgOrder->bom_id);

            $rmOrder = PlannedOrder::query()->where('product_id', $rmId)->first();
            $this->assertNotNull($rmOrder);
            $this->assertSame(PlannedOrder::TYPE_PURCHASE, $rmOrder->order_type);
            $this->assertEquals(20, $rmOrder->qty); // 2 per unit * 10

            $this->assertSame(1, DemandHeader::query()->where('source_type', DemandHeader::SOURCE_DEPENDENT)->count());
            $this->assertSame(1, DemandLine::query()->whereHas('header', fn ($q) => $q->where('source_type', DemandHeader::SOURCE_DEPENDENT))->count());
        });

        // Re-running with no state change must regenerate the SAME plan, not accumulate.
        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $tenant->run(function () use ($fgId, $rmId) {
            $this->assertSame(2, PlannedOrder::query()->count());
            $this->assertEquals(10, PlannedOrder::query()->where('product_id', $fgId)->first()->qty);
            $this->assertEquals(20, PlannedOrder::query()->where('product_id', $rmId)->first()->qty);
            $this->assertSame(1, DemandHeader::query()->where('source_type', DemandHeader::SOURCE_DEPENDENT)->count());
        });
    }

    public function test_releasing_purchase_order_prevents_duplicate_ordering_on_next_run(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $rmId = null;
        $tenant->run(function () use (&$rmId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $rmId = Product::query()->create([
                'sku' => 'MRP-RM-02', 'name' => 'Raw Material 2', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
            ItemPlanningParam::query()->create(['product_id' => $rmId, 'safety_stock_qty' => 0]);
        });

        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $rmId, 'need_by_date' => now()->addDays(5)->toDateString(), 'qty' => 20]],
        ])->assertRedirect('/pp/demand');

        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $orderId = null;
        $tenant->run(function () use (&$orderId, $rmId) {
            $order = PlannedOrder::query()->where('product_id', $rmId)->first();
            $this->assertNotNull($order);
            $this->assertEquals(20, $order->qty);
            $orderId = $order->id;
        });

        $this->patch("/pp/planned-orders/{$orderId}/release")->assertRedirect('/pp/planned-orders');

        $tenant->run(function () use ($orderId) {
            $order = PlannedOrder::query()->find($orderId);
            $this->assertSame(PlannedOrder::STATUS_RELEASED, $order->status);
            $this->assertSame(PurRequisitionHdr::class, $order->released_subject_type);
            $this->assertNotNull($order->released_subject_id);
            $this->assertTrue(PurRequisitionHdr::query()->whereKey($order->released_subject_id)->exists());
        });

        // Re-run MRP: the same 20 units are still demanded, but they're now covered by the
        // released (scheduled-receipt) requisition, so no second purchase order is created.
        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $tenant->run(function () use ($rmId, $orderId) {
            $this->assertSame(1, PlannedOrder::query()->where('product_id', $rmId)->count());
            $stillThere = PlannedOrder::query()->find($orderId);
            $this->assertNotNull($stillThere);
            $this->assertSame(PlannedOrder::STATUS_RELEASED, $stillThere->status);
        });
    }
}
