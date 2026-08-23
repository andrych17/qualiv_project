<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'account_holder_name' => ['nullable', 'string', 'max:150'],
            'currency_code' => ['required', 'string', 'size:3'],
            'gl_account_id' => ['required', 'integer'],
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

            $currencyCode = $this->input('currency_code');
            if ($currencyCode && ! Currency::query()->where('code', $currencyCode)->where('is_enabled', true)->exists()) {
                $validator->errors()->add('currency_code', 'The selected currency is not enabled.');
            }

            $accountId = $this->input('gl_account_id');
            if ($accountId && ! Account::query()->whereKey($accountId)->exists()) {
                $validator->errors()->add('gl_account_id', 'The selected GL account is invalid.');
            }

            if ($accountId && BankAccount::query()->where('gl_account_id', $accountId)->exists()) {
                $validator->errors()->add('gl_account_id', 'This GL account is already linked to another bank account.');
            }
        });
    }
}
