<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer',
            'batch_number' => 'required|string|max:60',
            'expiry_date' => 'nullable|date',
            'manufacture_date' => 'nullable|date',
            'supplier_reference' => 'nullable|string|max:100',
        ];
    }

    /** Schema-qualified tables (INVENTORY.*) can't be checked via `exists:`/`unique:` — see CRM's StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('product_id') && ! Product::query()->whereKey($this->input('product_id'))->exists()) {
                $validator->errors()->add('product_id', 'The selected product is invalid.');
            }
        });
    }
}
