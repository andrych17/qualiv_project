<?php

namespace Tests\Feature;

use App\Modules\CRM\Models\Partner;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\PP\Models\DemandForecast;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\ItemPlanningParam;
use App\Modules\Sales\Events\SalesOrderConfirmed;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** PP_SPECS.md §3B — Demand Aggregation: forecast sync, manual entry, Sales order event, safety-stock recalculation. */
class PPDemandAggregationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_forecast_crud_syncs_a_demand_line(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $productId = Product::query()->create([
                'sku' => 'DEM-01', 'name' => 'Demand Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
        });

        $this->post('/pp/demand-forecasts', [
            'product_id' => $productId,
            'period_start' => '2026-10-01',
            'qty' => 100,
        ])->assertRedirect('/pp/demand-forecasts');

        $forecastId = null;
        $tenant->run(function () use (&$forecastId, $productId) {
            $forecast = DemandForecast::query()->where('product_id', $productId)->first();
            $this->assertNotNull($forecast);
            $header = DemandHeader::query()->where('subject_type', DemandForecast::class)->where('subject_id', $forecast->id)->first();
            $this->assertNotNull($header);
            $this->assertSame(DemandHeader::SOURCE_FORECAST, $header->source_type);
            $line = $header->lines()->first();
            $this->assertEquals(100, $line->qty);
            $forecastId = $forecast->id;
        });

        // Update qty resyncs the same line (no duplicate header)
        $this->put("/pp/demand-forecasts/{$forecastId}", [
            'product_id' => $productId,
            'period_start' => '2026-10-01',
            'qty' => 150,
        ])->assertRedirect('/pp/demand-forecasts');

        $tenant->run(function () use ($forecastId) {
            $this->assertSame(1, DemandHeader::query()->where('subject_type', DemandForecast::class)->where('subject_id', $forecastId)->count());
            $header = DemandHeader::query()->where('subject_type', DemandForecast::class)->where('subject_id', $forecastId)->first();
            $this->assertEquals(150, $header->lines()->first()->qty);
        });

        // Delete forecast removes the demand header/line too
        $this->delete("/pp/demand-forecasts/{$forecastId}")->assertRedirect('/pp/demand-forecasts');

        $tenant->run(function () use ($forecastId) {
            $this->assertSame(0, DemandHeader::query()->where('subject_type', DemandForecast::class)->where('subject_id', $forecastId)->count());
        });
    }

    public function test_manual_demand_crud(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $productId = Product::query()->create([
                'sku' => 'DEM-02', 'name' => 'Manual Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
        });

        $this->get('/pp/demand')->assertOk()->assertInertia(fn ($page) => $page->component('PP/Demand/Index'));

        $this->post('/pp/demand', [
            'demand_date' => '2026-09-01',
            'note' => 'Rush job',
            'lines' => [
                ['product_id' => $productId, 'need_by_date' => '2026-09-15', 'qty' => 50],
            ],
        ])->assertRedirect('/pp/demand');

        $headerId = null;
        $tenant->run(function () use (&$headerId, $productId) {
            $header = DemandHeader::query()->where('source_type', DemandHeader::SOURCE_MANUAL)->first();
            $this->assertNotNull($header);
            $this->assertSame(1, $header->lines()->count());
            $this->assertEquals(50, $header->lines()->first()->qty);
            $headerId = $header->id;
        });

        $this->put("/pp/demand/{$headerId}", [
            'demand_date' => '2026-09-01',
            'note' => 'Rush job — revised',
            'lines' => [
                ['product_id' => $productId, 'need_by_date' => '2026-09-20', 'qty' => 75],
            ],
        ])->assertRedirect('/pp/demand');

        $tenant->run(function () use ($headerId) {
            $header = DemandHeader::query()->find($headerId);
            $this->assertEquals(75, $header->lines()->first()->qty);
        });

        $this->delete("/pp/demand/{$headerId}")->assertRedirect('/pp/demand');

        $tenant->run(function () use ($headerId) {
            $this->assertNull(DemandHeader::query()->find($headerId));
        });
    }

    public function test_sales_order_confirmed_event_creates_demand(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $tenant->run(function () {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'DEM-SO-01', 'name' => 'SO Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $customer = Partner::query()->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'organization', 'name' => 'Acme Co']);

            $order = SalesOrder::query()->create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'so_number' => 'SO-DEM-0001',
                'customer_id' => $customer->id,
                'status' => SalesOrder::STATUS_CONFIRMED,
            ]);
            SalesOrderLine::query()->create([
                'so_hdr_id' => $order->id, 'line_no' => 1, 'item_type' => 'product', 'product_id' => $product->id,
                'description' => 'SO Widget', 'qty_ordered' => 30, 'unit_price' => 10,
            ]);

            event(new SalesOrderConfirmed($order->fresh()->load('lines')));

            $header = DemandHeader::query()->where('subject_type', SalesOrder::class)->where('subject_id', $order->id)->first();
            $this->assertNotNull($header);
            $this->assertSame(DemandHeader::SOURCE_SALES_ORDER, $header->source_type);
            $this->assertEquals(30, $header->lines()->first()->qty);
        });
    }

    public function test_safety_stock_recalculation(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $productId = Product::query()->create([
                'sku' => 'DEM-SS-01', 'name' => 'Safety Stock Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
            ItemPlanningParam::query()->create(['product_id' => $productId, 'safety_stock_qty' => 20]);
        });

        // No stock on hand — shortfall of 20 should generate a demand line.
        $this->post('/pp/demand/recalculate-safety-stock')->assertRedirect('/pp/demand');

        $tenant->run(function () use ($productId) {
            $header = DemandHeader::query()->where('subject_type', ItemPlanningParam::class)->first();
            $this->assertNotNull($header);
            $this->assertSame(DemandHeader::SOURCE_SAFETY_STOCK, $header->source_type);
            $this->assertEquals(20, $header->lines()->first()->qty);
        });

        // Stock now covers safety stock — recalculating should remove the demand row.
        $tenant->run(function () use ($productId) {
            $warehouse = Warehouse::query()->create(['name' => 'Main']);
            $location = Location::query()->create(['warehouse_id' => $warehouse->id, 'code' => 'MAIN-A1', 'type' => Location::TYPE_BIN]);
            StockBalance::query()->create(['product_id' => $productId, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'qty_on_hand' => 25]);
        });

        $this->post('/pp/demand/recalculate-safety-stock')->assertRedirect('/pp/demand');

        $tenant->run(function () {
            $this->assertSame(0, DemandHeader::query()->where('subject_type', ItemPlanningParam::class)->count());
            $this->assertSame(0, DemandLine::query()->count());
        });
    }
}
