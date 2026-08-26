<?php

namespace App\Modules\Sales\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'so_hdr_id' => ['nullable', 'integer'],
            'accounting_invoice_id' => ['nullable', 'integer'],
            'reason_code' => ['required', 'string', 'max:50'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.so_line_id' => ['nullable', 'integer'],
            'lines.*.qty_returned' => ['required', 'numeric', 'min:0.001'],
            'lines.*.condition_notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $customerId = $this->input('customer_id');
            if ($customerId && ! Partner::query()->whereKey($customerId)->exists()) {
                $validator->errors()->add('customer_id', 'The selected customer is invalid.');
            }

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
