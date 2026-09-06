<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\MES\Models\BatchParameterReading;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Services\BatchExecutionService;
use App\Modules\MES\Services\YieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3I — Process Execution: Batch / Phase UI. */
class MesBatchExecutionTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_show_is_rejected_for_an_assembly_model_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('BE-0');
            $bom = $this->makeBom($product->id);
            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-BE0', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->get("/mes/shop-floor/{$orderId}/batch")->assertStatus(422);
    }

    public function test_show_renders_an_empty_batch_state_before_any_batch_exists(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('BE-1');
            $recipeId = $this->makeRecipe($product->id)->id;
            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-BE1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->get("/mes/shop-floor/{$orderId}/batch")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ShopFloor/Batch')->where('batch', null));

        // No batch to start/pause/resume/complete-phase yet.
        $this->post("/mes/shop-floor/{$orderId}/batch/start")->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post("/mes/shop-floor/{$orderId}/batch/pause")->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post("/mes/shop-floor/{$orderId}/batch/resume")->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->post("/mes/shop-floor/{$orderId}/batch/complete-phase")->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_batch_creation_rejects_a_recipe_with_no_process_phases_and_a_non_released_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $noPhasesOrderId = null;
        $draftOrderId = null;
        $tenant->run(function () use (&$noPhasesOrderId, &$draftOrderId) {
            $product = $this->makeProduct('BE-2');
            $recipe = $this->makeRecipe($product->id);
            $noPhasesOrderId = ProdOrder::query()->create([
                'order_number' => 'WO-BE2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
            $draftOrderId = ProdOrder::query()->create([
                'order_number' => 'WO-BE2D', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$noPhasesOrderId}/batch", ['planned_qty' => 5])
            ->assertRedirect()->assertSessionHasErrors(['recipe_id']);

        $this->post("/mes/shop-floor/{$draftOrderId}/batch", ['planned_qty' => 5])
            ->assertRedirect()->assertSessionHasErrors(['status']);
    }

    public function test_full_two_phase_batch_lifecycle_scales_ingredients_records_readings_and_completes_the_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $rawMaterialId = null;
        $tempParamId = null;
        $locationId = null;
        $tenant->run(function () use (&$orderId, &$rawMaterialId, &$tempParamId, &$locationId) {
            $product = $this->makeProduct('BE-3');
            $rawMaterial = $this->makeProduct('BE-3-RAW');
            $rawMaterialId = $rawMaterial->id;
            $recipe = $this->makeRecipe($product->id, ['batch_size' => 100]);
            $this->makeRecipeIngredient($recipe, $rawMaterialId, ['qty_per_batch' => 10]);

            $workCenter = $this->makeWorkCenter();
            $phase1 = ProcessPhase::query()->create([
                'recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id,
            ]);
            $tempParamId = ProcessParameter::query()->create([
                'process_phase_id' => $phase1->id, 'parameter_code' => 'TEMP', 'min_value' => 10, 'max_value' => 20,
            ])->id;
            ProcessPhase::query()->create([
                'recipe_id' => $recipe->id, 'seq' => 20, 'phase_name' => 'Cool', 'work_center_id' => $workCenter->id,
            ]);

            $warehouse = $this->makeWarehouse();
            $location = $this->makeLocation($warehouse);
            $locationId = $location->id;
            $this->receiveStock($warehouse, $rawMaterialId, 100, $rawMaterial->base_uom_id, $locationId);

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-BE3', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'warehouse_id' => $warehouse->id, 'qty' => 50, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        // Scaled to 50/100 of the recipe's batch_size => planned_qty defaults to order qty (50),
        // scale factor 0.5, so the 10-per-batch ingredient resolves to 5.
        $this->post("/mes/shop-floor/{$orderId}/batch")->assertRedirect()->assertSessionDoesntHaveErrors();

        $batchId = null;
        $tenant->run(function () use (&$batchId, $orderId, $rawMaterialId) {
            $batch = MesBatch::query()->where('order_id', $orderId)->firstOrFail();
            $batchId = $batch->id;
            $this->assertEqualsWithDelta(50.0, (float) $batch->planned_qty, 0.001);
            $this->assertSame(2, $batch->phases()->count());
            $ingredient = $batch->ingredients()->where('raw_material_product_id', $rawMaterialId)->first();
            $this->assertEqualsWithDelta(5.0, (float) $ingredient->resolved_qty, 0.001);
        });

        // Creating a second batch while one already exists is fine (MVP allows it, §4 note) —
        // latestBatch() always acts on the newest one; not exercised further here.

        $this->get("/mes/shop-floor/{$orderId}/batch")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ShopFloor/Batch')
                ->has('batch.ingredients', 1)
                ->has('batch.phases', 2)
                ->where('batch.status', MesBatch::STATUS_DRAFT));

        // Pause/resume/complete-phase before the batch has started.
        $this->post("/mes/shop-floor/{$orderId}/batch/pause")->assertRedirect()->assertSessionHasErrors(['status']);
        $this->post("/mes/shop-floor/{$orderId}/batch/resume")->assertRedirect()->assertSessionHasErrors(['status']);

        $this->post("/mes/shop-floor/{$orderId}/batch/start")->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId, $batchId) {
            $order = ProdOrder::query()->find($orderId);
            $this->assertSame(ProdOrder::STATUS_IN_PROGRESS, $order->status);
            $this->assertNotNull($order->actual_start);
            $batch = MesBatch::query()->find($batchId);
            $this->assertSame(MesBatch::STATUS_RUNNING, $batch->status);
            $this->assertSame(BatchPhase::STATUS_RUNNING, $batch->phases()->orderBy('seq')->first()->status);
        });

        // Starting again while already running is rejected.
        $this->post("/mes/shop-floor/{$orderId}/batch/start")->assertRedirect()->assertSessionHasErrors(['status']);

        $this->post("/mes/shop-floor/{$orderId}/batch/pause")->assertRedirect()->assertSessionDoesntHaveErrors();
        $tenant->run(function () use ($orderId, $batchId) {
            $this->assertSame(ProdOrder::STATUS_PAUSED, ProdOrder::query()->find($orderId)->status);
            $this->assertSame(MesBatch::STATUS_PAUSED, MesBatch::query()->find($batchId)->status);
        });

        $this->post("/mes/shop-floor/{$orderId}/batch/resume")->assertRedirect()->assertSessionDoesntHaveErrors();
        $tenant->run(function () use ($orderId, $batchId) {
            $this->assertSame(ProdOrder::STATUS_IN_PROGRESS, ProdOrder::query()->find($orderId)->status);
            $this->assertSame(MesBatch::STATUS_RUNNING, MesBatch::query()->find($batchId)->status);
        });

        // Complete phase 1 with one in-spec and one out-of-spec reading.
        $this->post("/mes/shop-floor/{$orderId}/batch/complete-phase", [
            'readings' => [
                ['process_parameter_id' => $tempParamId, 'value' => 15],
                ['process_parameter_id' => $tempParamId, 'value' => 25],
            ],
        ])->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($batchId) {
            $phases = MesBatch::query()->find($batchId)->phases()->orderBy('seq')->get();
            $this->assertSame(BatchPhase::STATUS_COMPLETED, $phases[0]->status);
            $this->assertSame(BatchPhase::STATUS_RUNNING, $phases[1]->status);
            $this->assertSame(2, BatchParameterReading::query()->where('batch_phase_id', $phases[0]->id)->count());
        });

        // Complete the final phase — closes the batch, posts finished output, computes yield.
        $this->post("/mes/shop-floor/{$orderId}/batch/complete-phase", ['location_id' => $locationId])
            ->assertRedirect()->assertSessionDoesntHaveErrors();

        $tenant->run(function () use ($orderId, $batchId) {
            $order = ProdOrder::query()->find($orderId);
            $this->assertSame(ProdOrder::STATUS_COMPLETED, $order->status);
            $this->assertNotNull($order->actual_end);
            $batch = MesBatch::query()->find($batchId);
            $this->assertSame(MesBatch::STATUS_COMPLETED, $batch->status);
            $this->assertEqualsWithDelta(100.0, (float) $batch->actual_yield_pct, 0.001);
            $this->assertSame(1, $order->productionOutputs()->where('output_type', 'finished')->count());
        });

        // No batch running once completed.
        $this->post("/mes/shop-floor/{$orderId}/batch/complete-phase")->assertRedirect()->assertSessionDoesntHaveErrors();
    }

    public function test_complete_phase_rejects_an_invalid_parameter_reference(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('BE-4');
            $recipe = $this->makeRecipe($product->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter();
            ProcessPhase::query()->create([
                'recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id,
            ]);

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-BE4', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/batch")->assertRedirect();
        $this->post("/mes/shop-floor/{$orderId}/batch/start")->assertRedirect();

        $this->post("/mes/shop-floor/{$orderId}/batch/complete-phase", [
            'readings' => [['process_parameter_id' => 999999, 'value' => 1]],
        ])->assertSessionHasErrors(['readings.0.process_parameter_id']);
    }

    public function test_complete_phase_rejects_a_final_phase_producing_more_than_one_serial_tracked_unit(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('BE-5', ['tracking_mode' => Product::TRACKING_SERIAL]);
            $recipe = $this->makeRecipe($product->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter();
            ProcessPhase::query()->create([
                'recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id,
            ]);

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-BE5', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 10, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->post("/mes/shop-floor/{$orderId}/batch", ['planned_qty' => 10])->assertRedirect();
        $this->post("/mes/shop-floor/{$orderId}/batch/start")->assertRedirect();

        $this->post("/mes/shop-floor/{$orderId}/batch/complete-phase")
            ->assertRedirect()->assertSessionHasErrors(['planned_qty']);
    }

    /**
     * Two of BatchExecutionService's own defensive guards are shadowed by their controller —
     * BatchExecutionController::assertProcess() already blocks an assembly-model order with a
     * 422 before create() is ever called, and completePhase() is always fed an already-filtered
     * running/paused phase — only reachable via a direct service call.
     */
    public function test_service_rejects_an_assembly_order_and_completing_a_pending_phase(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $assemblyProduct = $this->makeProduct('BE-7');
            $bom = $this->makeBom($assemblyProduct->id);
            $assemblyOrder = ProdOrder::query()->create([
                'order_number' => 'WO-BE7', 'product_id' => $assemblyProduct->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            try {
                app(BatchExecutionService::class)->create($assemblyOrder, []);
                $this->fail('Expected a ValidationException for an assembly-model order.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('production_model', $e->errors());
            }

            $processProduct = $this->makeProduct('BE-8');
            $recipe = $this->makeRecipe($processProduct->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter();
            ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);
            $processOrder = ProdOrder::query()->create([
                'order_number' => 'WO-BE8', 'product_id' => $processProduct->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $service = app(BatchExecutionService::class);
            $batch = $service->create($processOrder, []);
            $pendingPhase = $batch->phases()->first(); // still `pending` — start() was never called.

            try {
                $service->completePhase($batch, $pendingPhase, [], $this->adminUserId());
                $this->fail('Expected a ValidationException for completing a pending (not running) phase.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('phase', $e->errors());
            }
        });
    }

    /** BatchExecutionService::completePhase()'s "phase does not belong to the batch" guard is unreachable via the controller (it always resolves the running/paused phase from the batch itself) — only reachable via a direct, mismatched service call. */
    public function test_service_rejects_a_phase_that_does_not_belong_to_the_batch(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $productA = $this->makeProduct('BE-6A');
            $productB = $this->makeProduct('BE-6B');
            $recipeA = $this->makeRecipe($productA->id, ['batch_size' => 10]);
            $recipeB = $this->makeRecipe($productB->id, ['batch_size' => 10]);
            $workCenter = $this->makeWorkCenter();
            ProcessPhase::query()->create(['recipe_id' => $recipeA->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);
            ProcessPhase::query()->create(['recipe_id' => $recipeB->id, 'seq' => 10, 'phase_name' => 'Mix', 'work_center_id' => $workCenter->id]);

            $orderA = ProdOrder::query()->create([
                'order_number' => 'WO-BE6A', 'product_id' => $productA->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeA->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $orderB = ProdOrder::query()->create([
                'order_number' => 'WO-BE6B', 'product_id' => $productB->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeB->id, 'qty' => 10, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $service = app(BatchExecutionService::class);
            $batchA = $service->create($orderA, []);
            $batchB = $service->create($orderB, []);
            $foreignPhase = $batchB->phases()->first();

            try {
                $service->completePhase($batchA, $foreignPhase, [], $this->adminUserId());
                $this->fail('Expected a ValidationException for a phase not belonging to the batch.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('phase', $e->errors());
            }
        });
    }

    /** YieldService::forBatchPhaseIds()'s own empty-array guard is unreachable through its one real caller (BatchExecutionService::completePhase() at final completion) — a batch, once created, always has at least one phase (Phase 2's own established finding). Reachable only by calling the service directly. */
    public function test_yield_service_handles_an_empty_phase_id_list_directly(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $yield = app(YieldService::class)->forBatchPhaseIds([]);

            $this->assertSame(['good_qty' => 0.0, 'scrap_qty' => 0.0, 'yield_pct' => null], $yield);
        });
    }
}
