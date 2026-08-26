<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\VendorProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVendorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id' => 'required|integer',
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $partnerId = $this->input('partner_id');
            if ($partnerId) {
                if (! Partner::query()->whereKey($partnerId)->exists()) {
                    $validator->errors()->add('partner_id', 'The selected partner is invalid.');
                } elseif (VendorProfile::query()->where('partner_id', $partnerId)->exists()) {
                    $validator->errors()->add('partner_id', 'A vendor profile already exists for this partner.');
                }
            }
        });
    }
}
