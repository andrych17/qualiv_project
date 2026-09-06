<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\MES\Models\MaterialConsumption;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3J — Material Consumption & Production Output, both single write actions off a released order that call the real InventoryService. */
class MesMaterialAndOutputTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_admin_can_issue_and_return_an_untracked_material(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $materialId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$materialId, &$locationId) {
            $finished = $this->makeProduct('MAT-FG');
            $material = $this->makeProduct('MAT-RAW');
            $materialId = $material->id;
            $recipeId = $this->makeRecipe($finished->id)->id;
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;

            $this->receiveStock($warehouse, $materialId, 100, $material->base_uom_id, $locationId);

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-MAT1', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $materialId, 'type' => MaterialConsumption::TYPE_ISSUE,
            'qty' => 10, 'location_id' => $locationId,
        ])->assertRedirect();

        $tenant->run(function () use ($orderId, $materialId) {
            $consumption = MaterialConsumption::query()->where('order_id', $orderId)->where('type', MaterialConsumption::TYPE_ISSUE)->firstOrFail();
            $this->assertSame($materialId, $consumption->material_product_id);
            $this->assertEqualsWithDelta(10.0, (float) $consumption->qty, 0.001);
            $this->assertSame(1, ProdEvent::query()->where('order_id', $orderId)->where('event_type', ProdEvent::TYPE_MATERIAL_ISSUED)->count());
        });

        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $materialId, 'type' => MaterialConsumption::TYPE_RETURN,
            'qty' => 2, 'location_id' => $locationId,
        ])->assertRedirect();

        $tenant->run(function () use ($orderId) {
            $this->assertSame(1, MaterialConsumption::query()->where('order_id', $orderId)->where('type', MaterialConsumption::TYPE_RETURN)->count());
            $this->assertSame(1, ProdEvent::query()->where('order_id', $orderId)->where('event_type', ProdEvent::TYPE_MATERIAL_RETURNED)->count());
        });
    }

    public function test_issue_requires_a_lot_for_a_batch_tracked_material_and_a_serial_for_a_serial_tracked_one(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $batchMaterialId = null;
        $serialMaterialId = null;
        $lotId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$batchMaterialId, &$serialMaterialId, &$lotId, &$locationId) {
            $finished = $this->makeProduct('MAT-FG2');
            $batchMaterial = $this->makeProduct('MAT-BATCH', ['tracking_mode' => Product::TRACKING_BATCH]);
            $serialMaterial = $this->makeProduct('MAT-SERIAL', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $batchMaterialId = $batchMaterial->id;
            $serialMaterialId = $serialMaterial->id;
            $recipeId = $this->makeRecipe($finished->id)->id;
            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;

            $this->receiveStock($warehouse, $batchMaterialId, 50, $batchMaterial->base_uom_id, $locationId, 'LOT-1');
            $this->receiveStock($warehouse, $serialMaterialId, 1, $serialMaterial->base_uom_id, $locationId, null, 'SER-1');

            $lotId = StockBatch::query()->where('product_id', $batchMaterialId)->value('id');

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-MAT2', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        // Batch-tracked, no lot_id — rejected.
        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $batchMaterialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 5, 'location_id' => $locationId,
        ])->assertSessionHasErrors(['lot_id']);

        // Batch-tracked, with lot_id — succeeds.
        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $batchMaterialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 5, 'location_id' => $locationId, 'lot_id' => $lotId,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        // Serial-tracked, missing serial_number and qty > 1 — both rejected together.
        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $serialMaterialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 2, 'location_id' => $locationId,
        ])->assertSessionHasErrors(['serial_number', 'qty']);

        // Serial-tracked, with serial_number, qty=1 — succeeds and resolves serial_id.
        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $serialMaterialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1, 'location_id' => $locationId, 'serial_number' => 'SER-1',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId, $serialMaterialId) {
            $consumption = MaterialConsumption::query()->where('order_id', $orderId)->where('material_product_id', $serialMaterialId)->firstOrFail();
            $this->assertNotNull($consumption->serial_id);
        });

        // Returning a serial-tracked component is rejected outright.
        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $serialMaterialId, 'type' => MaterialConsumption::TYPE_RETURN, 'qty' => 1, 'location_id' => $locationId, 'serial_number' => 'SER-1',
        ])->assertSessionHasErrors(['serial_number']);

        // Lot that belongs to a different material is rejected.
        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $serialMaterialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1, 'location_id' => $locationId, 'lot_id' => $lotId, 'serial_number' => 'SER-1',
        ])->assertSessionHasErrors(['lot_id']);
    }

    public function test_material_consumption_store_rejects_missing_location_invalid_product_and_out_of_warehouse_location(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $materialId = null;
        $foreignLocationId = null;
        $tenant->run(function () use (&$orderId, &$materialId, &$foreignLocationId) {
            $finished = $this->makeProduct('MAT-FG3');
            $material = $this->makeProduct('MAT-RAW3');
            $materialId = $material->id;
            $recipeId = $this->makeRecipe($finished->id)->id;
            $warehouse = $this->makeWarehouse();
            $otherWarehouse = $this->makeWarehouse('Other Warehouse');
            $foreignLocationId = $this->makeLocation($otherWarehouse, 'B1')->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-MAT3', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => 999999, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1,
        ])->assertSessionHasErrors(['material_product_id']);

        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $materialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1,
        ])->assertSessionHasErrors(['location_id']);

        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $materialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1, 'location_id' => $foreignLocationId,
        ])->assertSessionHasErrors(['location_id']);
    }

    /** operation_ref cross-check only applies to an assembly-model order (§3J's Request docblock — a process order has no routing to check against). */
    public function test_material_consumption_store_rejects_an_operation_ref_that_does_not_belong_to_the_order_routing(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $materialId = null;
        $locationId = null;
        $foreignOpId = null;
        $tenant->run(function () use (&$orderId, &$materialId, &$locationId, &$foreignOpId) {
            $product = $this->makeProduct('MAT-FG6');
            $material = $this->makeProduct('MAT-RAW6');
            $materialId = $material->id;
            $bomId = $this->makeBom($product->id)->id;
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter);
            $foreignRouting = $this->makeRouting($this->makeProduct('MAT-OTHER')->id);
            $foreignOpId = $this->makeRoutingOp($foreignRouting, $workCenter)->id;
            $warehouse = $this->makeWarehouse();
            $locationId = $this->makeLocation($warehouse)->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-MAT6', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bomId, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id, 'qty' => 5, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/material-consumptions", [
            'material_product_id' => $materialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1, 'location_id' => $locationId, 'operation_ref' => $foreignOpId,
        ])->assertSessionHasErrors(['operation_ref']);
    }

    public function test_material_consumption_service_rejects_a_draft_order_and_an_order_with_no_warehouse(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $draftId = null;
        $noWarehouseId = null;
        $materialId = null;
        $locationId = null;
        $tenant->run(function () use (&$draftId, &$noWarehouseId, &$materialId, &$locationId) {
            $finished = $this->makeProduct('MAT-FG4');
            $material = $this->makeProduct('MAT-RAW4');
            $materialId = $material->id;
            $recipeId = $this->makeRecipe($finished->id)->id;
            $warehouse = $this->makeWarehouse();
            $locationId = $this->makeLocation($warehouse)->id;

            $draftId = ProdOrder::query()->create([
                'order_number' => 'WO-MAT4', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
            $noWarehouseId = ProdOrder::query()->create([
                'order_number' => 'WO-MAT5', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$draftId}/material-consumptions", [
            'material_product_id' => $materialId, 'type' => MaterialConsumption::TYPE_ISSUE, 'qty' => 1, 'location_id' => $locationId,
        ])->assertRedirect()->assertSessionHasErrors(['status']);

        // type=return, no location_id, so the FormRequest's own checks pass through cleanly and
        // the service's own "no warehouse set" guard is the one that fires.
        $this->post("/mes/prod-orders/{$noWarehouseId}/material-consumptions", [
            'material_product_id' => $materialId, 'type' => MaterialConsumption::TYPE_RETURN, 'qty' => 1,
        ])->assertRedirect()->assertSessionHasErrors(['warehouse_id']);
    }

    public function test_admin_can_record_finished_co_product_by_product_and_waste_output(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $finishedProductId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$finishedProductId, &$locationId) {
            $finished = $this->makeProduct('OUT-FG');
            $finishedProductId = $finished->id;
            $recipeId = $this->makeRecipe($finished->id)->id;
            $warehouse = $this->makeWarehouse();
            $locationId = $this->makeLocation($warehouse)->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-OUT1', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $finishedProductId, 'qty' => 8, 'location_id' => $locationId,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_WASTE, 'product_id' => $finishedProductId, 'qty' => 2,
            'location_id' => $locationId, 'reason_code' => 'defect', 'disposition' => ProductionOutput::DISPOSITION_SCRAP,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId) {
            $this->assertSame(1, ProductionOutput::query()->where('order_id', $orderId)->where('output_type', ProductionOutput::TYPE_FINISHED)->count());
            $waste = ProductionOutput::query()->where('order_id', $orderId)->where('output_type', ProductionOutput::TYPE_WASTE)->firstOrFail();
            $this->assertSame('defect', $waste->reason_code);
            $this->assertSame(ProductionOutput::DISPOSITION_SCRAP, $waste->disposition);
            $this->assertSame(2, ProdEvent::query()->where('order_id', $orderId)->where('event_type', ProdEvent::TYPE_OUTPUT_PRODUCED)->count());
        });
    }

    public function test_output_requires_a_lot_number_or_serial_number_for_tracked_products(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $batchProductId = null;
        $serialProductId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$batchProductId, &$serialProductId, &$locationId) {
            $recipeAnchor = $this->makeProduct('OUT-ANCHOR');
            $batchProduct = $this->makeProduct('OUT-BATCH', ['tracking_mode' => Product::TRACKING_BATCH]);
            $serialProduct = $this->makeProduct('OUT-SERIAL', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $batchProductId = $batchProduct->id;
            $serialProductId = $serialProduct->id;
            $recipeId = $this->makeRecipe($recipeAnchor->id)->id;
            $warehouse = $this->makeWarehouse();
            $locationId = $this->makeLocation($warehouse)->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-OUT2', 'product_id' => $recipeAnchor->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $batchProductId, 'qty' => 5, 'location_id' => $locationId,
        ])->assertSessionHasErrors(['lot_number']);

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $serialProductId, 'qty' => 2, 'location_id' => $locationId,
        ])->assertSessionHasErrors(['serial_number', 'qty']);

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $serialProductId, 'qty' => 1, 'location_id' => $locationId, 'serial_number' => 'FG-SER-1',
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId, $serialProductId) {
            $output = ProductionOutput::query()->where('order_id', $orderId)->where('product_id', $serialProductId)->firstOrFail();
            $this->assertNotNull($output->serial_id);
        });
    }

    public function test_production_output_service_rejects_a_draft_order_and_an_order_with_no_warehouse(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $draftId = null;
        $noWarehouseId = null;
        $productId = null;
        $tenant->run(function () use (&$draftId, &$noWarehouseId, &$productId) {
            $finished = $this->makeProduct('OUT-FG6');
            $productId = $finished->id;
            $recipeId = $this->makeRecipe($finished->id)->id;
            $warehouse = $this->makeWarehouse();

            $draftId = ProdOrder::query()->create([
                'order_number' => 'WO-OUT4', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
            $noWarehouseId = ProdOrder::query()->create([
                'order_number' => 'WO-OUT5', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$draftId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $productId, 'qty' => 1,
        ])->assertRedirect()->assertSessionHasErrors(['status']);

        $this->post("/mes/prod-orders/{$noWarehouseId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $productId, 'qty' => 1,
        ])->assertRedirect()->assertSessionHasErrors(['warehouse_id']);
    }

    public function test_output_store_rejects_missing_waste_reason_invalid_product_and_out_of_warehouse_location(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $productId = null;
        $foreignLocationId = null;
        $tenant->run(function () use (&$orderId, &$productId, &$foreignLocationId) {
            $finished = $this->makeProduct('OUT-FG5');
            $productId = $finished->id;
            $recipeId = $this->makeRecipe($finished->id)->id;
            $warehouse = $this->makeWarehouse();
            $otherWarehouse = $this->makeWarehouse('Other Warehouse 2');
            $foreignLocationId = $this->makeLocation($otherWarehouse, 'C1')->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-OUT3', 'product_id' => $finished->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'warehouse_id' => $warehouse->id, 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_WASTE, 'product_id' => $productId, 'qty' => 1,
        ])->assertSessionHasErrors(['reason_code']);

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => 999999, 'qty' => 1,
        ])->assertSessionHasErrors(['product_id']);

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $productId, 'qty' => 1, 'location_id' => $foreignLocationId,
        ])->assertSessionHasErrors(['location_id']);
    }

    public function test_output_store_rejects_an_operation_ref_that_does_not_belong_to_the_order_routing(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $productId = null;
        $locationId = null;
        $foreignOpId = null;
        $tenant->run(function () use (&$orderId, &$productId, &$locationId, &$foreignOpId) {
            $product = $this->makeProduct('OUT-FG7');
            $productId = $product->id;
            $bomId = $this->makeBom($product->id)->id;
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter);
            $foreignRouting = $this->makeRouting($this->makeProduct('OUT-OTHER')->id);
            $foreignOpId = $this->makeRoutingOp($foreignRouting, $workCenter)->id;
            $warehouse = $this->makeWarehouse();
            $locationId = $this->makeLocation($warehouse)->id;

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-OUT6', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bomId, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id, 'qty' => 5, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/production-outputs", [
            'output_type' => ProductionOutput::TYPE_FINISHED, 'product_id' => $productId, 'qty' => 1, 'location_id' => $locationId, 'operation_ref' => $foreignOpId,
        ])->assertSessionHasErrors(['operation_ref']);
    }
}
