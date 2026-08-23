<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxBuktiPotong;
use App\Modules\Accounting\Models\WithholdingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWithholdingTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20'],
            'bp_type' => ['nullable', 'string', 'in:'.implode(',', TaxBuktiPotong::TYPES)],
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_final' => ['boolean'],
            'gl_payable_account_id' => ['required', 'integer'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var WithholdingType $withholdingType */
            $withholdingType = $this->route('withholdingType');

            $accountId = $this->input('gl_payable_account_id');
            if ($accountId && ! Account::query()->whereKey($accountId)->exists()) {
                $validator->errors()->add('gl_payable_account_id', 'The selected account is invalid.');
            }

            $dupe = WithholdingType::query()
                ->where('company_id', $withholdingType->company_id)
                ->where('code', $this->input('code'))
                ->whereKeyNot($withholdingType->id)
                ->exists();
            if ($dupe) {
                $validator->errors()->add('code', 'This withholding code is already used in the selected company.');
            }
        });
    }
}
