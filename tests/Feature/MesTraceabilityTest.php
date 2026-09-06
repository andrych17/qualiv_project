<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\MES\Models\MaterialConsumption;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Services\TraceabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3K Traceability & Genealogy — TraceabilityController/TraceabilityService's
 * forward/backward recursive-CTE walk over §3J's own consumption/output tables. No dedicated
 * genealogy table — line coverage here is about exercising every PHP-level branch (found vs.
 * not-found, forward vs. backward, and the "no further rows" early-return in
 * outputsForOrders()/consumptionsForOrders()), not the SQL recursion depth itself.
 */
class MesTraceabilityTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    /** @return array<string, mixed> */
    private function seedTraceabilityChain($tenant): array
    {
        $ids = [];
        $tenant->run(function () use (&$ids) {
            $rawMaterial = $this->makeProduct('TRACE-RAW', ['tracking_mode' => Product::TRACKING_BATCH]);
            $intermediate = $this->makeProduct('TRACE-MID', ['tracking_mode' => Product::TRACKING_BATCH]);
            $finalProduct = $this->makeProduct('TRACE-FINAL');
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);

            $this->receiveStock($warehouse, $rawMaterial->id, 10, $rawMaterial->base_uom_id, $location->id, 'LOT-A');
            $lotA = StockBatch::query()->where('product_id', $rawMaterial->id)->where('batch_number', 'LOT-A')->first();

            $this->receiveStock($warehouse, $intermediate->id, 10, $intermediate->base_uom_id, $location->id, 'LOT-B');
            $lotB = StockBatch::query()->where('product_id', $intermediate->id)->where('batch_number', 'LOT-B')->first();

            $recipeMid = $this->makeRecipe($intermediate->id);
            $recipeFinal = $this->makeRecipe($finalProduct->id);

            $orderA = ProdOrder::query()->create([
                'order_number' => 'WO-TRACE-A', 'product_id' => $intermediate->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeMid->id, 'warehouse_id' => $warehouse->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            MaterialConsumption::query()->create([
                'order_id' => $orderA->id, 'material_product_id' => $rawMaterial->id, 'lot_id' => $lotA->id,
                'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1, 'created_at' => now(),
            ]);
            ProductionOutput::query()->create([
                'order_id' => $orderA->id, 'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $intermediate->id,
                'qty' => 1, 'lot_id' => $lotB->id, 'created_at' => now(),
            ]);

            $orderB = ProdOrder::query()->create([
                'order_number' => 'WO-TRACE-B', 'product_id' => $finalProduct->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeFinal->id, 'warehouse_id' => $warehouse->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            MaterialConsumption::query()->create([
                'order_id' => $orderB->id, 'material_product_id' => $intermediate->id, 'lot_id' => $lotB->id,
                'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1, 'created_at' => now(),
            ]);

            $ids = ['orderA' => $orderA->order_number, 'orderB' => $orderB->order_number];
        });

        return $ids;
    }

    public function test_forward_trace_walks_a_lot_through_a_produced_output_into_a_second_orders_consumption(): void
    {
        $tenant = $this->loginAsMesAdmin();
        $ids = $this->seedTraceabilityChain($tenant);

        $this->get('/mes/traceability?lot_number=LOT-A&direction=forward')->assertInertia(fn (Assert $page) => $page
            ->where('notFound', false)
            ->has('result.consumption_trail', 2)
            ->where('result.consumption_trail.0.order_number', $ids['orderA'])
            ->where('result.consumption_trail.1.order_number', $ids['orderB'])
            ->has('result.outputs_by_order', 1)
            ->where('result.outputs_by_order.0.product_sku', 'TRACE-MID')
        );
    }

    public function test_backward_trace_walks_from_a_finished_lot_back_through_its_consumed_material(): void
    {
        $tenant = $this->loginAsMesAdmin();
        $ids = $this->seedTraceabilityChain($tenant);

        $this->get('/mes/traceability?lot_number=LOT-B&direction=backward')->assertInertia(fn (Assert $page) => $page
            ->where('notFound', false)
            ->has('result.output_trail', 1)
            ->where('result.output_trail.0.order_number', $ids['orderA'])
            ->has('result.consumptions_by_order', 1)
            ->where('result.consumptions_by_order.0.material_sku', 'TRACE-RAW')
        );
    }

    public function test_forward_and_backward_trace_return_empty_related_data_for_a_lot_with_no_movements(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('TRACE-ISOLATED', ['tracking_mode' => Product::TRACKING_BATCH]);
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $this->receiveStock($warehouse, $product->id, 5, $product->base_uom_id, $location->id, 'LOT-C');
        });

        $this->get('/mes/traceability?lot_number=LOT-C&direction=forward')->assertInertia(fn (Assert $page) => $page
            ->where('notFound', false)
            ->has('result.consumption_trail', 0)
            ->has('result.outputs_by_order', 0)
        );

        $this->get('/mes/traceability?lot_number=LOT-C&direction=backward')->assertInertia(fn (Assert $page) => $page
            ->where('notFound', false)
            ->has('result.output_trail', 0)
            ->has('result.consumptions_by_order', 0)
        );
    }

    public function test_traceability_reports_not_found_for_an_unknown_lot_number(): void
    {
        $this->loginAsMesAdmin();

        $this->get('/mes/traceability?lot_number=UNKNOWN-LOT')->assertInertia(fn (Assert $page) => $page
            ->where('notFound', true)
            ->where('result', null)
        );
    }

    /**
     * TraceabilityService::forwardTrace()/backwardTrace()'s own "both null" early return is
     * controller-shadowed: TraceabilityController only ever calls them when at least one of
     * lot_number/serial_number resolved to a real row (the "neither resolved" case is routed to
     * `notFound` instead, without calling either method) — only reachable via a direct call.
     */
    public function test_forward_and_backward_trace_return_empty_for_no_lot_or_serial_via_direct_service_call(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $service = app(TraceabilityService::class);
            $this->assertSame([], $service->forwardTrace(null, null));
            $this->assertSame([], $service->backwardTrace(null, null));
        });
    }

    public function test_traceability_index_renders_without_filters(): void
    {
        $this->loginAsMesAdmin();

        $this->get('/mes/traceability')->assertInertia(fn (Assert $page) => $page
            ->where('notFound', false)
            ->where('result', null)
            ->where('filters.direction', 'backward')
        );
    }
}
