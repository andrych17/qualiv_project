<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\PurCatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:40'],
            'description' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'unit' => ['required', 'string', 'max:20'],
            'preferred_supplier_id' => ['nullable', 'integer'],
            'negotiated_price' => ['nullable', 'numeric', 'min:0'],
            'price_valid_from' => ['nullable', 'date'],
            'price_valid_to' => ['nullable', 'date', 'after_or_equal:price_valid_from'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $itemId = $this->route('catalog_item')?->id ?? $this->route('catalog')?->id ?? $this->route('item')?->id;
            $itemCode = $this->input('item_code');
            if ($itemCode && PurCatalogItem::query()->where('item_code', $itemCode)->where('id', '!=', $itemId)->exists()) {
                $validator->errors()->add('item_code', 'The item code has already been taken.');
            }

            $catId = $this->input('category_id');
            if ($catId && ! Category::query()->whereKey($catId)->exists()) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }

            $suppId = $this->input('preferred_supplier_id');
            if ($suppId && ! Partner::query()->whereKey($suppId)->exists()) {
                $validator->errors()->add('preferred_supplier_id', 'The selected preferred supplier is invalid.');
            }
        });
    }
}
