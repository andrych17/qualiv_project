<?php

namespace App\Modules\Legal\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\LandObject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLandObjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'certificate_type' => ['required', Rule::in(LandObject::CERTIFICATE_TYPES)],
            'certificate_number' => 'required|string|max:100',
            'nib' => 'nullable|string|max:100',
            'address' => 'required|string|max:255',
            'area_m2' => 'nullable|numeric|min:0',
            'njop_reference' => 'nullable|string|max:150',
            'current_owner_partner_id' => 'nullable|integer',
            'status' => ['required', Rule::in(LandObject::STATUSES)],
        ];
    }

    /** exists:CRM.partners,id can't be used — see StoreMatterRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ownerId = $this->input('current_owner_partner_id');
            if ($ownerId && ! Partner::query()->whereKey($ownerId)->exists()) {
                $validator->errors()->add('current_owner_partner_id', 'The selected owner is invalid.');
            }
        });
    }
}
