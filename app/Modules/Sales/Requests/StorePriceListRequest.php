<?php

namespace App\Modules\Sales\Requests;

use App\Modules\Sales\Models\Territory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePriceListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'size:3'],
            'territory_id' => ['nullable', 'integer'],
            'customer_segment' => ['nullable', 'string', 'max:50'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'is_tenant_default' => ['boolean'],
            'is_active' => ['boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.item_type' => ['required_with:lines', 'in:product,service'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required_with:lines', 'string', 'max:255'],
            'lines.*.unit_price' => ['required_with:lines', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $territoryId = $this->input('territory_id');
            if ($territoryId && ! Territory::query()->whereKey($territoryId)->exists()) {
                $validator->errors()->add('territory_id', 'The selected territory is invalid.');
            }
        });
    }
}
