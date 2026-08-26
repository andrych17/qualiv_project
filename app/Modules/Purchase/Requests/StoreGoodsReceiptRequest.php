<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurOrderLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_id' => ['required', 'integer'],
            'received_at' => ['nullable', 'date'],
            'receiver_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'discrepancy_notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.po_line_id' => ['required', 'integer'],
            'lines.*.quantity_received' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.condition_notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $poId = $this->input('po_id');
            if ($poId && ! PurOrderHdr::query()->whereKey($poId)->exists()) {
                $validator->errors()->add('po_id', 'The selected purchase order is invalid.');
            }

            foreach ((array) $this->input('lines', []) as $index => $line) {
                if (! empty($line['po_line_id']) && ! PurOrderLine::query()->whereKey($line['po_line_id'])->exists()) {
                    $validator->errors()->add("lines.{$index}.po_line_id", 'The selected order line is invalid.');
                }
            }
        });
    }
}
