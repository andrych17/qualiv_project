<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'account_holder_name' => ['nullable', 'string', 'max:150'],
            'currency_code' => ['required', 'string', 'size:3'],
            'gl_account_id' => ['required', 'integer'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $currencyCode = $this->input('currency_code');
            if ($currencyCode && ! Currency::query()->where('code', $currencyCode)->where('is_enabled', true)->exists()) {
                $validator->errors()->add('currency_code', 'The selected currency is not enabled.');
            }

            $accountId = $this->input('gl_account_id');
            if ($accountId && ! Account::query()->whereKey($accountId)->exists()) {
                $validator->errors()->add('gl_account_id', 'The selected GL account is invalid.');
            }

            /** @var BankAccount $bankAccount */
            $bankAccount = $this->route('bankAccount');
            if ($accountId && BankAccount::query()->where('gl_account_id', $accountId)->whereKeyNot($bankAccount->id)->exists()) {
                $validator->errors()->add('gl_account_id', 'This GL account is already linked to another bank account.');
            }
        });
    }
}
