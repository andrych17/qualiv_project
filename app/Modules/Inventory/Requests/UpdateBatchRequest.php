<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Product can't be changed once a batch exists — a lot number belongs to the product it was received against. */
class UpdateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_number' => 'required|string|max:60',
            'expiry_date' => 'nullable|date',
            'manufacture_date' => 'nullable|date',
            'supplier_reference' => 'nullable|string|max:100',
        ];
    }
}
