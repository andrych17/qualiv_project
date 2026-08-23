<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'account_code' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:150'],
            'account_type' => ['required', 'string', 'in:'.implode(',', Account::TYPES)],
            'normal_balance' => ['required', 'string', 'in:'.implode(',', Account::BALANCES)],
            'parent_account_id' => ['nullable', 'integer'],
            'is_control_account' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    // exists:ACCOUNTING.accounts,id can't be used — Laravel's exists rule parses the
    // dot as connection.table, not schema.table (see DMS\Requests\StoreFolderRequest).
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            $parentId = $this->input('parent_account_id');
            if ($parentId && ! Account::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_account_id', 'The selected parent account is invalid.');
            }

            if (Account::query()->where('company_id', $companyId)->where('account_code', $this->input('account_code'))->exists()) {
                $validator->errors()->add('account_code', 'This account code is already used in the selected company.');
            }
        });
    }
}
