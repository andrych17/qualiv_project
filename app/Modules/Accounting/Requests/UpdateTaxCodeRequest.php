<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTaxCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_type' => ['required', 'string', 'in:'.implode(',', TaxCode::TYPES)],
            'gl_account_id' => ['required', 'integer'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var TaxCode $taxCode */
            $taxCode = $this->route('taxCode');

            $accountId = $this->input('gl_account_id');
            if ($accountId && ! Account::query()->whereKey($accountId)->exists()) {
                $validator->errors()->add('gl_account_id', 'The selected account is invalid.');
            }

            $dupe = TaxCode::query()
                ->where('company_id', $taxCode->company_id)
                ->where('code', $this->input('code'))
                ->whereKeyNot($taxCode->id)
                ->exists();
            if ($dupe) {
                $validator->errors()->add('code', 'This tax code is already used in the selected company.');
            }
        });
    }
}
