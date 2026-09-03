<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\PpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * PP_SPECS.md §3L — the two constraint checks genuinely buildable against real data today:
 * Material (a negative available-to-promise) and the TYPE_LATE_ORDER/TYPE_LATE_PURCHASE split by
 * order_type. Resource/Sequence/Quality/Labor stay unbuilt (MES/HCM certification data don't
 * exist) and Tank is already covered by PPCapacityPlanTest via CapacityPlanService's generic
 * overload check — see MrpService's own class docblock for why those aren't exercised here.
 */
class PPProductionConstraintsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_negative_available_to_promise_writes_a_material_shortage_exception(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'PC-MAT-01', 'name' => 'Constraint Test Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $productId = $product->id;

            $warehouse = Warehouse::query()->create(['name' => 'PC Warehouse']);
            $location = Location::query()->create(['warehouse_id' => $warehouse->id, 'code' => 'PC-01', 'type' => Location::TYPE_BIN]);
            // 5 on hand but 20 already reserved — available-to-promise is -15 before MRP even runs.
            StockBalance::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 5]);
            StockReservation::query()->create([
                'product_id' => $productId, 'warehouse_id' => $warehouse->id, 'qty' => 20,
                'status' => StockReservation::STATUS_ACTIVE, 'subject_type' => 'test', 'subject_id' => 1,
            ]);
        });

        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'need_by_date' => now()->addDays(30)->toDateString(), 'qty' => 10]],
        ])->assertRedirect('/pp/demand');

        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $firstOrderId = null;
        $firstExceptionId = null;
        $tenant->run(function () use ($productId, &$firstOrderId, &$firstExceptionId) {
            $order = PlannedOrder::query()->where('product_id', $productId)->first();
            $this->assertNotNull($order);
            $firstOrderId = $order->id;

            $exception = PpException::query()->where('exception_type', PpException::TYPE_MATERIAL_SHORTAGE)->first();
            $this->assertNotNull($exception);
            $this->assertSame(PpException::SUBJECT_PLANNED_ORDER, $exception->subject_type);
            $this->assertSame($order->id, $exception->subject_id);
            $this->assertSame(PpException::STATUS_OPEN, $exception->status);
            $firstExceptionId = $exception->id;
        });

        // A planner acknowledges it before the next run — that must survive as history, not
        // vanish, once MRP regenerates the order it points at.
        $this->patch("/pp/exceptions/{$firstExceptionId}/acknowledge")->assertRedirect();

        // Re-run: the underlying planned order is deleted and recreated with a new id (MRP is
        // regenerative), so the SAME still-true condition must produce exactly one still-OPEN
        // exception — not a second duplicate, and not silently dropping the acknowledged one.
        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $tenant->run(function () use ($productId, $firstOrderId, $firstExceptionId) {
            $this->assertSame(
                PpException::STATUS_RESOLVED,
                PpException::query()->find($firstExceptionId)->status,
                'the acknowledged exception tied to the now-deleted order must be resolved, not deleted or left open',
            );

            $newOrder = PlannedOrder::query()->where('product_id', $productId)->first();
            $this->assertNotSame($firstOrderId, $newOrder->id);

            $openOnes = PpException::query()->where('exception_type', PpException::TYPE_MATERIAL_SHORTAGE)->where('status', PpException::STATUS_OPEN)->get();
            $this->assertCount(1, $openOnes);
            $this->assertSame($newOrder->id, $openOnes->first()->subject_id);
        });
    }

    public function test_late_purchase_order_gets_its_own_exception_type_distinct_from_late_production(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $productId = Product::query()->create([
                'sku' => 'PC-PUR-01', 'name' => 'Constraint Purchase Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;

            // No BOM/recipe => a purchased raw material. Long lead time pushes the computed
            // need_by_date into the past even though the demand line's own need-by is future.
            ItemPlanningParam::query()->create(['product_id' => $productId, 'lead_time_days' => 30]);
        });

        $this->post('/pp/demand', [
            'demand_date' => now()->toDateString(),
            'lines' => [['product_id' => $productId, 'need_by_date' => now()->addDays(5)->toDateString(), 'qty' => 10]],
        ])->assertRedirect('/pp/demand');

        $this->post('/pp/planned-orders/run-mrp')->assertRedirect('/pp/planned-orders');

        $tenant->run(function () use ($productId) {
            $order = PlannedOrder::query()->where('product_id', $productId)->first();
            $this->assertSame(PlannedOrder::TYPE_PURCHASE, $order->order_type);

            $this->assertNotNull(PpException::query()->where('exception_type', PpException::TYPE_LATE_PURCHASE)->where('subject_id', $order->id)->first());
            $this->assertNull(PpException::query()->where('exception_type', PpException::TYPE_LATE_ORDER)->where('subject_id', $order->id)->first());
        });
    }
}
