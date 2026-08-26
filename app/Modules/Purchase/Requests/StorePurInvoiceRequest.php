<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurOrderLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePurInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_id' => ['required', 'integer'],
            'supplier_invoice_no' => ['required', 'string', 'max:60'],
            'supplier_invoice_date' => ['required', 'date'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'dms_document_id' => ['nullable', 'integer'],
            'submission_channel' => ['nullable', 'string', 'in:manual,supplier_upload_link'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.po_line_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
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
