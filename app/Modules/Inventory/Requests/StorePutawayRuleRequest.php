<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3R: condition is exactly one of `product_id` or `category_id` — never both, never neither. */
class StorePutawayRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer',
            'product_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'target_location_id' => 'required|integer',
            'priority_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasProduct = ! empty($this->input('product_id'));
            $hasCategory = ! empty($this->input('category_id'));
            if ($hasProduct === $hasCategory) {
                $validator->errors()->add('product_id', 'Choose exactly one condition: a specific product, or a category.');
            }

            if ($this->input('warehouse_id') && ! Warehouse::query()->whereKey($this->input('warehouse_id'))->exists()) {
                $validator->errors()->add('warehouse_id', 'The selected warehouse is invalid.');
            }
            if ($this->input('category_id') && ! ProductCategory::query()->whereKey($this->input('category_id'))->exists()) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }

            $locationId = $this->input('target_location_id');
            $warehouseId = $this->input('warehouse_id');
            if ($locationId && $warehouseId && ! Location::query()->where('id', $locationId)->where('warehouse_id', $warehouseId)->exists()) {
                $validator->errors()->add('target_location_id', 'The target location must belong to the selected warehouse.');
            }
        });
    }
}
