<?php

namespace App\Modules\Legal\Requests;

use App\Modules\Legal\Models\PartyRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDeedPartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_type_id' => 'required|integer',
            'identity_name' => 'nullable|string|max:255',
            'identity_id_number' => 'nullable|string|max:100',
            'identity_address' => 'nullable|string|max:500',
        ];
    }

    /** exists:LEGAL.party_role_types,id can't be used — see StoreMatterRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $roleTypeId = $this->input('role_type_id');
            if ($roleTypeId && ! PartyRoleType::query()->whereKey($roleTypeId)->exists()) {
                $validator->errors()->add('role_type_id', 'The selected role is invalid.');
            }
        });
    }
}
