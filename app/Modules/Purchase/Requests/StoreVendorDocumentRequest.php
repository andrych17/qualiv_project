<?php

namespace App\Modules\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:25600',
            'doc_type' => 'required|in:license,insurance,tax_cert,other',
            'title' => 'required|string|max:150',
            'expiry_date' => 'nullable|date',
        ];
    }
}
