<?php

namespace App\Modules\Legal\Requests;

use App\Modules\CRM\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'testator_partner_id' => 'required|integer',
        ];
    }

    /** exists:CRM.partners,id can't be used — see StoreMatterRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $id = $this->input('testator_partner_id');
            if ($id && ! Partner::query()->whereKey($id)->exists()) {
                $validator->errors()->add('testator_partner_id', 'The selected testator is invalid.');
            }
        });
    }
}
