<?php

namespace App\Modules\Purchase\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'incoterms' => 'nullable|string|max:20',
            'preferred_currency' => 'nullable|string|size:3',
            'tax_registration_no' => 'nullable|string|max:40',
            'bank_name' => 'nullable|string|max:100',
            'bank_account' => 'nullable|string|max:60',
            'is_preferred' => 'boolean',
            'onboarding_status' => 'in:pending,active,suspended',
        ];
    }
}
