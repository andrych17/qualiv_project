<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\CashTransaction;
use App\Modules\Accounting\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3F — record + immediately post a cash in/out entry (the human submitting this form is the review step). */
class StoreCashTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'bank_account_id' => ['required', 'integer'],
            'direction' => ['required', 'string', 'in:'.CashTransaction::DIRECTION_IN.','.CashTransaction::DIRECTION_OUT],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'offset_account_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
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

            $bankAccountId = $this->input('bank_account_id');
            if ($bankAccountId && ! BankAccount::query()->whereKey($bankAccountId)->exists()) {
                $validator->errors()->add('bank_account_id', 'The selected bank account is invalid.');
            }

            $offsetAccountId = $this->input('offset_account_id');
            if ($offsetAccountId && ! Account::query()->whereKey($offsetAccountId)->exists()) {
                $validator->errors()->add('offset_account_id', 'The selected account is invalid.');
            }
        });
    }
}
