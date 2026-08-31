<?php

namespace App\Modules\PP\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\PP\Models\ItemPlanningParam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreItemPlanningParamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer',
            'make_type' => 'nullable|in:mts,mto',
            'min_lot_qty' => 'nullable|numeric|min:0',
            'max_lot_qty' => 'nullable|numeric|min:0',
            'fixed_lot_qty' => 'nullable|numeric|min:0',
            'economic_lot_qty' => 'nullable|numeric|min:0',
            'safety_stock_qty' => 'nullable|numeric|min:0',
            'lead_time_days' => 'nullable|integer|min:0',
            'planning_lead_time_days' => 'nullable|integer|min:0',
            'order_multiple' => 'nullable|numeric|min:0',
            'scrap_pct' => 'nullable|numeric|min:0|max:100',
            'yield_pct_override' => 'nullable|numeric|min:0|max:100',
            'production_calendar_ref' => 'nullable|string|max:100',
            'preferred_line_ref_id' => 'nullable|integer',
            'alternate_line_ref_id' => 'nullable|integer',
            'planning_fence_days' => 'nullable|integer|min:0',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    /** Schema-qualified tables (PP, INVENTORY) can't be checked via `exists:`/`unique:` — see Inventory's StoreProductRequest. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $productId = $this->input('product_id');

            if ($productId && ! Product::query()->whereKey($productId)->exists()) {
                $validator->errors()->add('product_id', 'The selected product is invalid.');
            }

            if ($productId && ItemPlanningParam::query()->where('product_id', $productId)->exists()) {
                $validator->errors()->add('product_id', 'This product already has planning parameters.');
            }

            $min = $this->input('min_lot_qty');
            $max = $this->input('max_lot_qty');
            if ($min !== null && $max !== null && (float) $min > (float) $max) {
                $validator->errors()->add('max_lot_qty', 'Maximum lot quantity must be greater than or equal to the minimum.');
            }
        });
    }
}
