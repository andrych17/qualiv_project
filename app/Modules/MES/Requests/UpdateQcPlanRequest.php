<?php

namespace App\Modules\MES\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\MES\Models\QcCharacteristic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQcPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'nullable|integer',
            'name' => 'required|string|max:150',
            'characteristics' => 'required|array|min:1',
            'characteristics.*.characteristic_name' => 'required|string|max:150',
            'characteristics.*.spec_type' => ['required', Rule::in([QcCharacteristic::SPEC_NUMERIC, QcCharacteristic::SPEC_PASS_FAIL])],
            'characteristics.*.target_value' => 'nullable|numeric',
            'characteristics.*.min_value' => 'nullable|numeric',
            'characteristics.*.max_value' => 'nullable|numeric',
            'characteristics.*.uom_code' => 'nullable|string|max:10',
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
