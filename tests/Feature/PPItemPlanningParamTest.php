<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\ItemPlanningParam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** PP_SPECS.md §3A — Item Planning Parameters end-to-end HTTP smoke test. */
class PPItemPlanningParamTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_starter_plan_blocks_pp_module(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/pp/item-planning-params')->assertForbidden();
    }

    public function test_admin_can_crud_item_planning_params_on_full_plan(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        // Index renders empty
        $this->get('/pp/item-planning-params')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/ItemPlanningParams/Index'));

        $productId = null;
        $tenant->run(function () use (&$productId) {
            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'PP-WIDGET-01',
                'name' => 'PP Test Widget',
                'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO,
                'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $productId = $product->id;
        });

        // Create form renders
        $this->get('/pp/item-planning-params/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/ItemPlanningParams/Create'));

        // Store
        $this->post('/pp/item-planning-params', [
            'product_id' => $productId,
            'make_type' => 'mts',
            'safety_stock_qty' => 25,
            'lead_time_days' => 7,
            'planning_lead_time_days' => 3,
            'scrap_pct' => 2.5,
            'planning_fence_days' => 14,
        ])->assertRedirect('/pp/item-planning-params');

        $paramId = null;
        $tenant->run(function () use (&$paramId, $productId) {
            $param = ItemPlanningParam::query()->where('product_id', $productId)->first();
            $this->assertNotNull($param);
            $this->assertSame('mts', $param->make_type);
            $this->assertEquals(25, $param->safety_stock_qty);
            $this->assertEquals(7, $param->lead_time_days);
            $paramId = $param->id;
        });

        // Duplicate product_id is rejected
        $this->post('/pp/item-planning-params', [
            'product_id' => $productId,
            'safety_stock_qty' => 10,
        ])->assertSessionHasErrors('product_id');

        // Index lists the new row
        $this->get('/pp/item-planning-params')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/ItemPlanningParams/Index')
                ->where('params.data.0.product_sku', 'PP-WIDGET-01')
                ->where('params.data.0.make_type', 'mts')
            );

        // Edit form prefills
        $this->get("/pp/item-planning-params/{$paramId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('PP/ItemPlanningParams/Edit')
                ->where('param.product_id', $productId)
                ->where('param.safety_stock_qty', 25)
            );

        // Update
        $this->put("/pp/item-planning-params/{$paramId}", [
            'product_id' => $productId,
            'make_type' => 'mto',
            'safety_stock_qty' => 40,
            'lead_time_days' => 10,
            'planning_lead_time_days' => 3,
            'scrap_pct' => 2.5,
            'planning_fence_days' => 14,
        ])->assertRedirect('/pp/item-planning-params');

        $tenant->run(function () use ($paramId) {
            $param = ItemPlanningParam::query()->find($paramId);
            $this->assertSame('mto', $param->make_type);
            $this->assertEquals(40, $param->safety_stock_qty);
        });

        // Delete
        $this->delete("/pp/item-planning-params/{$paramId}")->assertRedirect('/pp/item-planning-params');

        $tenant->run(function () use ($paramId) {
            $this->assertNull(ItemPlanningParam::query()->find($paramId));
        });
    }
}
