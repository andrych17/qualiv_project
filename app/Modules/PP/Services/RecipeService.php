<?php

namespace App\Modules\PP\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\PP\Models\Recipe;
use App\Modules\PP\Models\RecipeIngredient;
use Illuminate\Support\Facades\DB;

/** PP_SPECS.md §3D — process recipe/formula header + ingredients CRUD, plus pure formula scaling. */
class RecipeService
{
    public const ENTITY = 'pp_recipe';

    public function __construct(protected CustomFieldService $customFields) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Recipe
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($data, $custom) {
            if ($data['is_active'] ?? true) {
                $this->deactivateOthers($data['product_id']);
            }

            $recipe = Recipe::query()->create([
                'product_id' => $data['product_id'],
                'version' => $data['version'] ?? $this->nextVersion($data['product_id']),
                'batch_size' => $data['batch_size'],
                'uom_code' => $data['uom_code'] ?? null,
                'expected_yield_pct' => $data['expected_yield_pct'] ?? 100,
                'expected_waste_pct' => $data['expected_waste_pct'] ?? 0,
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $this->syncIngredients($recipe, $data['ingredients'] ?? []);
            $this->customFields->sync(self::ENTITY, $recipe->id, $custom);

            return $recipe->load('ingredients');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Recipe $recipe, array $data): Recipe
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($recipe, $data, $custom) {
            if (($data['is_active'] ?? false) && ! $recipe->is_active) {
                $this->deactivateOthers($recipe->product_id);
            }

            $recipe->update([
                'batch_size' => $data['batch_size'] ?? $recipe->batch_size,
                'uom_code' => $data['uom_code'] ?? null,
                'expected_yield_pct' => $data['expected_yield_pct'] ?? $recipe->expected_yield_pct,
                'expected_waste_pct' => $data['expected_waste_pct'] ?? $recipe->expected_waste_pct,
                'effective_from' => $data['effective_from'] ?? $recipe->effective_from,
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $recipe->is_active,
            ]);

            $this->syncIngredients($recipe, $data['ingredients'] ?? []);
            $this->customFields->sync(self::ENTITY, $recipe->id, $custom);

            return $recipe->refresh()->load('ingredients');
        });
    }

    public function delete(Recipe $recipe): void
    {
        DB::transaction(function () use ($recipe) {
            $this->customFields->deleteFor(self::ENTITY, $recipe->id);
            $recipe->delete();
        });
    }

    /**
     * §3D formula scaling — pure calculation, no stored scaled rows:
     * `qty = ingredient.qty_per_batch * targetBatchSize / recipe.batch_size`.
     *
     * @return list<array{product_id: int, qty: float, uom_code: string|null}>
     */
    public function scale(Recipe $recipe, float $targetBatchSize): array
    {
        return $recipe->ingredients->map(fn (RecipeIngredient $ingredient) => [
            'product_id' => $ingredient->raw_material_product_id,
            'qty' => (float) $ingredient->qty_per_batch * $targetBatchSize / (float) $recipe->batch_size,
            'uom_code' => $ingredient->uom_code,
        ])->all();
    }

    private function nextVersion(int $productId): int
    {
        return (int) Recipe::query()->where('product_id', $productId)->max('version') + 1;
    }

    private function deactivateOthers(int $productId): void
    {
        Recipe::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /** @param  list<array<string, mixed>>  $ingredients */
    private function syncIngredients(Recipe $recipe, array $ingredients): void
    {
        $recipe->ingredients()->delete();

        foreach ($ingredients as $ingredient) {
            if (empty($ingredient['raw_material_product_id']) || ! isset($ingredient['qty_per_batch'])) {
                continue;
            }

            RecipeIngredient::query()->create([
                'recipe_id' => $recipe->id,
                'raw_material_product_id' => $ingredient['raw_material_product_id'],
                'qty_per_batch' => $ingredient['qty_per_batch'],
                'uom_code' => $ingredient['uom_code'] ?? null,
            ]);
        }
    }
}
