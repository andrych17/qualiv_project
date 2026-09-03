<?php

namespace App\Modules\MES\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** MES_SPECS.md §3G — Shop Floor "COMPLETE" action payload. */
class StoreOperationCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qty_completed' => 'required|numeric|min:0.0001',
            'qty_rejected' => 'nullable|numeric|min:0',
            'location_id' => 'nullable|integer',
            'reject_reason_code' => 'nullable|string|max:30',
            'lot_number' => 'nullable|string|max:30',
            'serial_number' => 'nullable|string|max:100',
        ];
    }
}
