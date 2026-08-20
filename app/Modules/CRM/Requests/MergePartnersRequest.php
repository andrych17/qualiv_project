<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MergePartnersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'survivor_partner_id' => 'required|integer',
            'loser_partner_id' => 'required|integer|different:survivor_partner_id',
        ];
    }

    /** exists:CRM.partners,id can't be used — see StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['survivor_partner_id', 'loser_partner_id'] as $field) {
                $id = $this->input($field);
                if ($id && ! Partner::query()->whereKey($id)->exists()) {
                    $validator->errors()->add($field, 'The selected partner is invalid.');
                }
            }
        });
    }
}
