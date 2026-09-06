<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;
use App\Modules\PP\Services\RecipeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** PP_SPECS.md §3D — BOM/Recipe master data CRUD, including the one-active-version-per-product rule. */
class PPBomRecipeCrudTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_bom_crud_and_one_active_version_per_product(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        [$parentId, $componentId] = $this->makeTwoProducts($tenant, 'BOM');

        $this->get('/pp/boms')->assertOk()->assertInertia(fn ($page) => $page->component('PP/Boms/Index'));
        $this->get('/pp/boms/create')->assertOk()->assertInertia(fn ($page) => $page->component('PP/Boms/Create'));

        // Self-reference guard.
        $this->post('/pp/boms', [
            'product_id' => $parentId,
            'lines' => [['component_product_id' => $parentId, 'qty_per_parent_unit' => 1]],
        ])->assertSessionHasErrors('lines.0.component_product_id');

        $this->post('/pp/boms', [
            'product_id' => $parentId,
            'lines' => [['component_product_id' => $componentId, 'qty_per_parent_unit' => 3, 'scrap_pct' => 5]],
        ])->assertRedirect('/pp/boms');

        $bomId = null;
        $tenant->run(function () use (&$bomId, $parentId) {
            $bom = Bom::query()->where('product_id', $parentId)->first();
            $this->assertNotNull($bom);
            $this->assertTrue($bom->is_active);
            $this->assertSame(1, $bom->lines()->count());
            $bomId = $bom->id;
        });

        // A second active BOM for the same product deactivates the first one.
        $this->post('/pp/boms', [
            'product_id' => $parentId,
            'lines' => [['component_product_id' => $componentId, 'qty_per_parent_unit' => 4]],
        ])->assertRedirect('/pp/boms');

        $tenant->run(function () use ($bomId, $parentId) {
            $this->assertFalse(Bom::query()->find($bomId)->is_active);
            $this->assertSame(1, Bom::query()->where('product_id', $parentId)->where('is_active', true)->count());
        });

        $this->get("/pp/boms/{$bomId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('PP/Boms/Edit'));

        $this->delete("/pp/boms/{$bomId}")->assertRedirect('/pp/boms');
        $tenant->run(function () use ($bomId) {
            $this->assertNull(Bom::query()->find($bomId));
        });
    }

    public function test_recipe_crud_and_scaling(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        [$parentId, $ingredientId] = $this->makeTwoProducts($tenant, 'RECIPE');

        $this->post('/pp/recipes', [
            'product_id' => $parentId,
            'batch_size' => 100,
            'uom_code' => 'L',
            'ingredients' => [['raw_material_product_id' => $ingredientId, 'qty_per_batch' => 10]],
        ])->assertRedirect('/pp/recipes');

        $recipeId = null;
        $tenant->run(function () use (&$recipeId, $parentId, $ingredientId) {
            $recipe = Recipe::query()->where('product_id', $parentId)->first();
            $this->assertNotNull($recipe);
            $recipeId = $recipe->id;

            $scaled = app(RecipeService::class)->scale($recipe->load('ingredients'), 250);
            $this->assertCount(1, $scaled);
            $this->assertSame($ingredientId, $scaled[0]['product_id']);
            // 10 per 100L batch, scaled to 250L => 25
            $this->assertEqualsWithDelta(25.0, $scaled[0]['qty'], 0.0001);
        });

        $this->get("/pp/recipes/{$recipeId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('PP/Recipes/Edit'));

        $this->put("/pp/recipes/{$recipeId}", [
            'batch_size' => 100,
            'uom_code' => 'L',
            'ingredients' => [['raw_material_product_id' => $ingredientId, 'qty_per_batch' => 20]],
        ])->assertRedirect('/pp/recipes');

        $tenant->run(function () use ($recipeId) {
            $this->assertEquals(20, Recipe::query()->find($recipeId)->ingredients()->first()->qty_per_batch);
        });

        $this->delete("/pp/recipes/{$recipeId}")->assertRedirect('/pp/recipes');
        $tenant->run(function () use ($recipeId) {
            $this->assertNull(Recipe::query()->find($recipeId));
        });
    }

    /** @return array{0: int, 1: int} */
    private function makeTwoProducts($tenant, string $prefix): array
    {
        $ids = [];
        $tenant->run(function () use (&$ids, $prefix) {
            $uom = Uom::query()->create(['code' => 'PCS-'.$prefix, 'name' => 'Pieces']);
            $ids[] = Product::query()->create([
                'sku' => $prefix.'-PARENT', 'name' => $prefix.' Parent', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
            $ids[] = Product::query()->create([
                'sku' => $prefix.'-CHILD', 'name' => $prefix.' Child', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ])->id;
        });

        return $ids;
    }
}
