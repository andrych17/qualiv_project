<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTaxCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_type' => ['required', 'string', 'in:'.implode(',', TaxCode::TYPES)],
            'gl_account_id' => ['required', 'integer'],
            'is_active' => ['boolean'],
        ];
    }

    // exists:ACCOUNTING.*,id can't be used — Laravel's exists rule parses the dot as
    // connection.table, not schema.table (see DMS\Requests\StoreFolderRequest).
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            $accountId = $this->input('gl_account_id');
            if ($accountId && ! Account::query()->whereKey($accountId)->exists()) {
                $validator->errors()->add('gl_account_id', 'The selected account is invalid.');
            }

            if (TaxCode::query()->where('company_id', $companyId)->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'This tax code is already used in the selected company.');
            }
        });
    }
}
