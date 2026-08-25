<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Draft receipts are freely editable (§3D) — this only checks structure and that
 * referenced rows exist. Whether a line's location actually belongs to the header's
 * warehouse is a post-time-only rule (GoodsReceiptService::post()), not enforced here.
 */
class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer',
            'receipt_date' => 'required|date',
            'subject_type' => 'nullable|string|max:60',
            'subject_id' => 'nullable|string|max:60',
            'reference_number' => 'nullable|string|max:60',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer',
            'lines.*.unit_cost' => 'required|numeric|min:0',
            'lines.*.destination_location_id' => 'nullable|integer',
            'lines.*.batch_number' => 'nullable|string|max:60',
            'lines.*.batch_expiry_date' => 'nullable|date',
            'lines.*.batch_manufacture_date' => 'nullable|date',
            'lines.*.batch_supplier_reference' => 'nullable|string|max:100',
            'lines.*.serial_numbers' => 'nullable|array',
            'lines.*.serial_numbers.*' => 'nullable|string|max:80',
        ];
    }

    /** Schema-qualified tables (INVENTORY.*) can't be checked via `exists:`/`unique:` — see CRM's StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('warehouse_id') && ! Warehouse::query()->whereKey($this->input('warehouse_id'))->exists()) {
                $validator->errors()->add('warehouse_id', 'The selected warehouse is invalid.');
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $productId = $line['product_id'] ?? null;
                if ($productId && ! Product::query()->whereKey($productId)->exists()) {
                    $validator->errors()->add("lines.{$i}.product_id", 'The selected product is invalid.');
                }

                $uomId = $line['uom_id'] ?? null;
                if ($uomId && ! Uom::query()->whereKey($uomId)->exists()) {
                    $validator->errors()->add("lines.{$i}.uom_id", 'The selected UoM is invalid.');
                }
            }
        });
    }
}
