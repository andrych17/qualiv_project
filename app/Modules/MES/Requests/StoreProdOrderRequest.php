<?php

namespace App\Modules\MES\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\Routing;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3A — `bom_id`/`recipe_id`/`routing_id` are resolved server-side from the chosen product + production model (§3B), never picked directly here. */
class StoreProdOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer',
            'production_model' => ['required', Rule::in([ProdOrder::MODEL_ASSEMBLY, ProdOrder::MODEL_PROCESS])],
            'qty' => 'required|numeric|min:0.0001',
            'uom_code' => 'nullable|string|max:10',
            'planned_start' => 'nullable|date',
            'planned_end' => 'nullable|date|after_or_equal:planned_start',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'warehouse_id' => 'nullable|integer',
            'line_area' => 'nullable|string|max:100',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $productId = $this->input('product_id');
            $model = $this->input('production_model');

            if ($productId && ! Product::query()->whereKey($productId)->exists()) {
                $validator->errors()->add('product_id', 'The selected product is invalid.');

                return;
            }

            if (! $productId || ! $model) {
                return;
            }

            if ($model === ProdOrder::MODEL_ASSEMBLY) {
                if (! Bom::query()->active()->where('product_id', $productId)->exists()) {
                    $validator->errors()->add('product_id', 'This product has no active BOM — create one in Production Planning first.');
                }
                if (! Routing::query()->active()->where('product_id', $productId)->exists()) {
                    $validator->errors()->add('product_id', 'This product has no active Routing — create one in Manufacturing Execution first.');
                }
            } else {
                if (! Recipe::query()->active()->where('product_id', $productId)->exists()) {
                    $validator->errors()->add('product_id', 'This product has no active Recipe — create one in Production Planning first.');
                }
            }

            $warehouseId = $this->input('warehouse_id');
            if ($warehouseId && ! Warehouse::query()->whereKey($warehouseId)->exists()) {
                $validator->errors()->add('warehouse_id', 'The selected warehouse is invalid.');
            }
        });
    }
}
