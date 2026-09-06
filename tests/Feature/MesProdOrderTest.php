<?php

namespace Tests\Feature;

use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3A — Production Order: single header for both production models. */
class MesProdOrderTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_admin_can_create_an_assembly_order_and_it_resolves_the_active_bom_and_routing(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $productId = null;
        $warehouseId = null;
        $tenant->run(function () use (&$productId, &$warehouseId) {
            $product = $this->makeProduct('MO-1');
            $productId = $product->id;
            $this->makeBom($productId);
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($productId);
            $this->makeRoutingOp($routing, $workCenter);
            $warehouseId = $this->makeWarehouse()->id;
        });

        $this->get('/mes/prod-orders')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ProdOrders/Index'));
        $this->get('/mes/prod-orders/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ProdOrders/Create'));

        $this->post('/mes/prod-orders', [
            'product_id' => $productId, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
            'qty' => 10, 'uom_code' => 'PCS', 'warehouse_id' => $warehouseId,
        ])->assertRedirect();

        $tenant->run(function () use ($productId) {
            $order = ProdOrder::query()->where('product_id', $productId)->firstOrFail();
            $this->assertStringStartsWith('WO-', $order->order_number);
            $this->assertNotNull($order->bom_id);
            $this->assertNotNull($order->routing_id);
            $this->assertSame(ProdOrder::STATUS_DRAFT, $order->status);
        });
    }

    public function test_admin_can_create_a_process_order_and_it_resolves_the_active_recipe(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $product = $this->makeProduct('MO-2');
            $productId = $product->id;
            $this->makeRecipe($productId);
        });

        $this->post('/mes/prod-orders', [
            'product_id' => $productId, 'production_model' => ProdOrder::MODEL_PROCESS, 'qty' => 100, 'uom_code' => 'KG',
        ])->assertRedirect();

        $tenant->run(function () use ($productId) {
            $order = ProdOrder::query()->where('product_id', $productId)->firstOrFail();
            $this->assertNotNull($order->recipe_id);
            $this->assertNull($order->bom_id);
        });
    }

    public function test_store_rejects_invalid_product_missing_master_data_and_invalid_warehouse(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $productWithoutBomId = null;
        $productWithoutRecipeId = null;
        $tenant->run(function () use (&$productWithoutBomId, &$productWithoutRecipeId) {
            $productWithoutBomId = $this->makeProduct('MO-3')->id;
            $productWithoutRecipeId = $this->makeProduct('MO-4')->id;
        });

        $this->post('/mes/prod-orders', [
            'product_id' => 999999, 'production_model' => ProdOrder::MODEL_ASSEMBLY, 'qty' => 1,
        ])->assertSessionHasErrors(['product_id']);

        $this->post('/mes/prod-orders', [
            'product_id' => $productWithoutBomId, 'production_model' => ProdOrder::MODEL_ASSEMBLY, 'qty' => 1,
        ])->assertSessionHasErrors(['product_id']);

        $this->post('/mes/prod-orders', [
            'product_id' => $productWithoutRecipeId, 'production_model' => ProdOrder::MODEL_PROCESS, 'qty' => 1,
        ])->assertSessionHasErrors(['product_id']);

        $this->post('/mes/prod-orders', [
            'product_id' => $productWithoutBomId, 'production_model' => ProdOrder::MODEL_ASSEMBLY, 'qty' => 1, 'warehouse_id' => 999999,
        ])->assertSessionHasErrors(['warehouse_id']);

        $this->post('/mes/prod-orders', [
            'product_id' => $productWithoutBomId, 'production_model' => 'not-a-model', 'qty' => 1,
        ])->assertSessionHasErrors(['production_model']);

        $this->post('/mes/prod-orders', [
            'product_id' => $productWithoutBomId, 'production_model' => ProdOrder::MODEL_ASSEMBLY, 'qty' => 0,
        ])->assertSessionHasErrors(['qty']);

        // A valid product_id but an omitted production_model hits the `after()` closure's own
        // "nothing to resolve yet" early-return, distinct from the invalid-product-id return above.
        $this->post('/mes/prod-orders', ['product_id' => $productWithoutBomId, 'qty' => 1])
            ->assertSessionHasErrors(['production_model']);
    }

    public function test_only_a_draft_order_can_be_edited_updated_or_deleted(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $draftId = null;
        $releasedId = null;
        $tenant->run(function () use (&$draftId, &$releasedId) {
            $product = $this->makeProduct('MO-5');
            $recipeId = $this->makeRecipe($product->id)->id;
            $draftId = ProdOrder::query()->create([
                'order_number' => 'WO-D1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
            $releasedId = ProdOrder::query()->create([
                'order_number' => 'WO-R1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->get("/mes/prod-orders/{$draftId}/edit")->assertOk();
        $this->get("/mes/prod-orders/{$releasedId}/edit")->assertStatus(422);

        $this->put("/mes/prod-orders/{$draftId}", ['qty' => 8, 'uom_code' => 'KG'])->assertRedirect();
        $tenant->run(function () use ($draftId) {
            $this->assertEqualsWithDelta(8.0, (float) ProdOrder::query()->find($draftId)->qty, 0.001);
        });

        $this->put("/mes/prod-orders/{$releasedId}", ['qty' => 8, 'uom_code' => 'KG'])->assertRedirect()
            ->assertSessionHasErrors(['status']);

        $this->put("/mes/prod-orders/{$draftId}", ['qty' => 1, 'uom_code' => 'KG', 'warehouse_id' => 999999])
            ->assertSessionHasErrors(['warehouse_id']);

        $this->delete("/mes/prod-orders/{$releasedId}")->assertRedirect()->assertSessionHasErrors(['status']);
        $this->delete("/mes/prod-orders/{$draftId}")->assertRedirect();
        $tenant->run(function () use ($draftId) {
            $this->assertNull(ProdOrder::query()->find($draftId));
        });
    }

    public function test_release_moves_a_draft_order_to_released_and_writes_an_order_released_event(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('MO-6');
            $recipeId = $this->makeRecipe($product->id)->id;
            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-REL1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$orderId}/release")->assertRedirect();

        $tenant->run(function () use ($orderId) {
            $order = ProdOrder::query()->find($orderId);
            $this->assertSame(ProdOrder::STATUS_RELEASED, $order->status);
            $this->assertSame(1, ProdEvent::query()->where('order_id', $orderId)->where('event_type', ProdEvent::TYPE_ORDER_RELEASED)->count());
        });

        // Releasing an already-released order is rejected.
        $this->post("/mes/prod-orders/{$orderId}/release")->assertRedirect()->assertSessionHasErrors(['status']);
    }

    public function test_cancel_rejects_an_already_finished_order(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $draftId = null;
        $completedId = null;
        $tenant->run(function () use (&$draftId, &$completedId) {
            $product = $this->makeProduct('MO-7');
            $recipeId = $this->makeRecipe($product->id)->id;
            $draftId = ProdOrder::query()->create([
                'order_number' => 'WO-C1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_DRAFT,
            ])->id;
            $completedId = ProdOrder::query()->create([
                'order_number' => 'WO-C2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_COMPLETED,
            ])->id;
        });

        $this->post("/mes/prod-orders/{$draftId}/cancel")->assertRedirect();
        $tenant->run(function () use ($draftId) {
            $this->assertSame(ProdOrder::STATUS_CANCELLED, ProdOrder::query()->find($draftId)->status);
        });

        $this->post("/mes/prod-orders/{$completedId}/cancel")->assertRedirect()->assertSessionHasErrors(['status']);
    }

    public function test_show_renders_order_detail_with_events_and_a_parent_order_link(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $childId = null;
        $tenant->run(function () use (&$orderId, &$childId) {
            $product = $this->makeProduct('MO-8');
            $recipeId = $this->makeRecipe($product->id)->id;
            $order = ProdOrder::query()->create([
                'order_number' => 'WO-SHOW1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_RELEASED,
            ]);
            $orderId = $order->id;
            ProdEvent::query()->create([
                'order_id' => $orderId, 'event_type' => ProdEvent::TYPE_ORDER_RELEASED,
                'payload' => ['qty' => 5], 'occurred_at' => now(), 'user_id' => $this->adminUserId(),
            ]);
            $childId = ProdOrder::query()->create([
                'order_number' => 'WO-SHOW2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_DRAFT, 'parent_order_id' => $orderId,
            ])->id;
        });

        $this->get("/mes/prod-orders/{$orderId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/ProdOrders/Show')
                ->where('order.order_number', 'WO-SHOW1')
                ->has('order.events', 1)
                ->where('order.yield.yield_pct', null)
                ->where('qcPlan', null));

        $this->get("/mes/prod-orders/{$childId}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('order.parent_order.id', $orderId));
    }

    /** An assembly order with a routing+op and a warehouse exercises show()'s `routingOps`/`locations` option builders — the process order above leaves both empty. */
    public function test_show_renders_routing_ops_and_locations_for_an_assembly_order_with_a_warehouse(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $orderId = null;
        $tenant->run(function () use (&$orderId) {
            $product = $this->makeProduct('MO-10');
            $bomId = $this->makeBom($product->id)->id;
            $workCenter = $this->makeWorkCenter();
            $routing = $this->makeRouting($product->id);
            $this->makeRoutingOp($routing, $workCenter);
            $warehouse = $this->makeWarehouse();
            $this->makeLocation($warehouse, 'A1');

            $orderId = ProdOrder::query()->create([
                'order_number' => 'WO-SHOW3', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bomId, 'routing_id' => $routing->id, 'warehouse_id' => $warehouse->id,
                'qty' => 5, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ])->id;
        });

        $this->get("/mes/prod-orders/{$orderId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('routingOps', 1)->has('locations', 1));
    }

    public function test_index_filters_by_search_production_model_and_status(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $tenant->run(function () {
            $product = $this->makeProduct('MO-9');
            $recipeId = $this->makeRecipe($product->id)->id;
            ProdOrder::query()->create([
                'order_number' => 'WO-IDX1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipeId, 'qty' => 5, 'uom_code' => 'KG', 'status' => ProdOrder::STATUS_DRAFT,
            ]);
        });

        $this->get('/mes/prod-orders?search=WO-IDX1')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
        $this->get('/mes/prod-orders?production_model='.ProdOrder::MODEL_ASSEMBLY)->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));
        $this->get('/mes/prod-orders?status='.ProdOrder::STATUS_DRAFT.'&sort=order_number&direction=asc')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1));
    }
}
