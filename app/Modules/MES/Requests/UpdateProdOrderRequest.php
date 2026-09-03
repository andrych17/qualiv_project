<?php

namespace App\Modules\MES\Requests;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3A — only draft orders are editable (enforced by ProdOrderService); `product_id`/`production_model` stay immutable after creation, so this request never accepts them. */
class UpdateProdOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty' => 'required|numeric|min:0.0001',
            'uom_code' => 'nullable|string|max:10',
            'planned_start' => 'nullable|date',
            'planned_end' => 'nullable|date|after_or_equal:planned_start',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'warehouse_id' => 'nullable|integer',
            'line_area' => 'nullable|string|max:100',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $warehouseId = $this->input('warehouse_id');
            if ($warehouseId && ! Warehouse::query()->whereKey($warehouseId)->exists()) {
                $validator->errors()->add('warehouse_id', 'The selected warehouse is invalid.');
            }
        });
    }
}
