<?php

namespace App\Modules\PP\Requests;

use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMpsHeaderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer',
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
        });
    }
}
