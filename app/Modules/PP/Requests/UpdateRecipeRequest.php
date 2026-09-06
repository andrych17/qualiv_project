<?php

namespace App\Modules\PP\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\PP\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_size' => 'required|numeric|min:0.0001',
            'uom_code' => 'nullable|string|max:10',
            'expected_yield_pct' => 'nullable|numeric|min:0|max:100',
            'expected_waste_pct' => 'nullable|numeric|min:0|max:100',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'is_active' => 'nullable|boolean',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.raw_material_product_id' => 'required|integer',
            'ingredients.*.qty_per_batch' => 'required|numeric|min:0.000001',
            'ingredients.*.uom_code' => 'nullable|string|max:10',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Recipe $recipe */
            $recipe = $this->route('recipe');

            foreach ((array) $this->input('ingredients', []) as $i => $ingredient) {
                $ingredientId = $ingredient['raw_material_product_id'] ?? null;
                if ($ingredientId && ! Product::query()->whereKey($ingredientId)->exists()) {
                    $validator->errors()->add("ingredients.{$i}.raw_material_product_id", 'The selected ingredient is invalid.');
                }
                if ($ingredientId && $recipe && (int) $ingredientId === (int) $recipe->product_id) {
                    $validator->errors()->add("ingredients.{$i}.raw_material_product_id", 'A recipe cannot use its own product as an ingredient.');
                }
            }
        });
    }
}
