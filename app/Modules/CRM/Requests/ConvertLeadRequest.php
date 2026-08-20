<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_type' => ['required', Rule::in([Partner::TYPE_INDIVIDUAL, Partner::TYPE_ORGANIZATION])],
            'role_type_id' => 'required|integer',
        ];
    }

    /** exists:CRM.partner_role_types,id can't be used — see StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $roleTypeId = $this->input('role_type_id');
            if ($roleTypeId && ! PartnerRoleType::query()->whereKey($roleTypeId)->exists()) {
                $validator->errors()->add('role_type_id', 'The selected role is invalid.');
            }
        });
    }
}
