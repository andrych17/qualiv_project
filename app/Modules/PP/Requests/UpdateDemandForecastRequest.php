<?php

namespace App\Modules\PP\Requests;

use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDemandForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer',
            'period_start' => 'required|date',
            'qty' => 'required|numeric|min:0',
            'source' => 'nullable|in:manual,import',
            'note' => 'nullable|string|max:255',
        ];
    }

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
