<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateGoodsIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer',
            'issue_date' => 'required|date',
            'subject_type' => 'nullable|string|max:60',
            'subject_id' => 'nullable|string|max:60',
            'reason' => 'nullable|in:consumption,sample,write_off_pending_adjustment_review',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer',
            'lines.*.source_location_id' => 'nullable|integer',
            'lines.*.batch_id' => 'nullable|integer',
            'lines.*.expiry_override_reason' => 'nullable|string|max:255',
            'lines.*.serial_numbers' => 'nullable|array',
            'lines.*.serial_numbers.*' => 'nullable|string|max:80',
        ];
    }

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
