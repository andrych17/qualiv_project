<?php

namespace App\Modules\Legal\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\PartyRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDeedPartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id' => 'nullable|integer',
            'role_type_id' => 'required|integer',
            'identity_name' => 'required_without:partner_id|nullable|string|max:255',
            'identity_id_number' => 'nullable|string|max:100',
            'identity_address' => 'nullable|string|max:500',
        ];
    }

    /** exists:LEGAL.*,id / CRM.*,id can't be used — see StoreMatterRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $roleTypeId = $this->input('role_type_id');
            if ($roleTypeId && ! PartyRoleType::query()->whereKey($roleTypeId)->exists()) {
                $validator->errors()->add('role_type_id', 'The selected role is invalid.');
            }

            $partnerId = $this->input('partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('partner_id', 'The selected party is invalid.');
            }
        });
    }
}
