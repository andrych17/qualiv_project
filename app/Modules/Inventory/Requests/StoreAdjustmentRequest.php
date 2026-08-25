<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\AdjustmentReason;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Draft adjustments are freely editable (§3G) — this only checks structure and that
 * referenced rows exist. Location/warehouse matching and the live-balance variance
 * calculation are post-time-only rules (AdjustmentService::post()), not enforced here.
 */
class StoreAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer',
            'location_id' => 'required|integer',
            'adjustment_date' => 'required|date',
            'reason_id' => 'required|integer',
            'reference' => 'nullable|string|max:60',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.system_qty' => 'nullable|numeric',
            'lines.*.counted_qty' => 'required|numeric|min:0',
            'lines.*.batch_id' => 'nullable|integer',
        ];
    }

    /** Schema-qualified tables (INVENTORY.*) can't be checked via `exists:`/`unique:` — see CRM's StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('warehouse_id') && ! Warehouse::query()->whereKey($this->input('warehouse_id'))->exists()) {
                $validator->errors()->add('warehouse_id', 'The selected warehouse is invalid.');
            }

            if ($this->input('reason_id') && ! AdjustmentReason::query()->whereKey($this->input('reason_id'))->exists()) {
                $validator->errors()->add('reason_id', 'The selected reason is invalid.');
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $productId = $line['product_id'] ?? null;
                if ($productId && ! Product::query()->whereKey($productId)->exists()) {
                    $validator->errors()->add("lines.{$i}.product_id", 'The selected product is invalid.');
                }
            }
        });
    }
}
