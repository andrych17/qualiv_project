<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string|max:64',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category_id' => 'nullable|integer',
            'base_uom_id' => 'required|integer',
            'costing_method' => 'nullable|in:fifo,average',
            'reorder_point' => 'nullable|numeric|min:0',
            'reorder_quantity' => 'nullable|numeric|min:0',
            'tracking_mode' => 'nullable|in:none,batch,serial',
            'barcodes' => 'nullable|array',
            'barcodes.*.barcode' => 'nullable|string|max:64',
            'barcodes.*.type' => 'required_with:barcodes.*.barcode|in:primary,case_pack,alternate',
            'barcodes.*.unit_multiplier' => 'nullable|numeric|min:0.000001',
            'uom_conversions' => 'nullable|array',
            'uom_conversions.*.uom_id' => 'nullable|integer',
            'uom_conversions.*.conversion_factor' => 'required_with:uom_conversions.*.uom_id|numeric|min:0.000001',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    /** Schema-qualified tables (INVENTORY.*) can't be checked via `exists:`/`unique:` — see CRM's StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (Product::query()->where('sku', $this->input('sku'))->exists()) {
                $validator->errors()->add('sku', 'This SKU is already in use.');
            }

            $categoryId = $this->input('category_id');
            if ($categoryId && ! ProductCategory::query()->whereKey($categoryId)->exists()) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }

            $baseUomId = $this->input('base_uom_id');
            if ($baseUomId && ! Uom::query()->whereKey($baseUomId)->exists()) {
                $validator->errors()->add('base_uom_id', 'The selected base UoM is invalid.');
            }
        });
    }
}
