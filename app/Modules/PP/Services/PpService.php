<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;

/**
 * PP_SPECS.md §3D/§5/§7 — the cross-module contract MES will call for BOM/Recipe composition
 * data (`getActiveBom`/`getActiveRecipe`/`scaleRecipe`), the mirror image of PP calling
 * `MesService::listResources()` for equipment identity. Its first real caller is PP's own
 * `MrpService` (explosion needs exactly these two lookups), which is what justifies building
 * it now rather than leaving it a named-but-unimplemented Open Item.
 */
class PpService
{
    public function __construct(protected RecipeService $recipes) {}

    public function getActiveBom(int $productId): ?Bom
    {
        return Bom::query()->where('product_id', $productId)->active()->with('lines')->first();
    }

    public function getActiveRecipe(int $productId): ?Recipe
    {
        return Recipe::query()->where('product_id', $productId)->active()->with('ingredients')->first();
    }

    /** @return list<array{product_id: int, qty: float, uom_code: string|null}> */
    public function scaleRecipe(int $recipeId, float $targetBatchSize): array
    {
        $recipe = Recipe::query()->with('ingredients')->findOrFail($recipeId);

        return $this->recipes->scale($recipe, $targetBatchSize);
    }
}
