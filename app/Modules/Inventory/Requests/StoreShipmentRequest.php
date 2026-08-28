<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer',
            'carrier' => 'nullable|string|max:60',
            'tracking_number' => 'nullable|string|max:80',
            'ship_date' => 'nullable|date',
            'pack_list_ids' => 'required|array|min:1',
            'pack_list_ids.*' => 'integer',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('warehouse_id') && ! Warehouse::query()->whereKey($this->input('warehouse_id'))->exists()) {
                $validator->errors()->add('warehouse_id', 'The selected warehouse is invalid.');
            }
        });
    }
}
