<?php

namespace App\Modules\Sales\Requests;

use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'so_hdr_id' => ['required', 'integer'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'source_location_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.so_line_id' => ['required', 'integer'],
            'lines.*.qty_shipped' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $soHdrId = $this->input('so_hdr_id');
            if ($soHdrId && ! SalesOrder::query()->whereKey($soHdrId)->exists()) {
                $validator->errors()->add('so_hdr_id', 'The selected sales order is invalid.');
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $soLineId = $line['so_line_id'] ?? null;
                if ($soLineId && ! SalesOrderLine::query()->whereKey($soLineId)->exists()) {
                    $validator->errors()->add("lines.{$i}.so_line_id", 'The selected order line is invalid.');
                }
            }
        });
    }
}
