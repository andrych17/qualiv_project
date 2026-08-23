<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3F — record + immediately post an inter-account transfer (the human submitting this form is the review step). */
class StoreCashTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'from_bank_account_id' => ['required', 'integer'],
            'to_bank_account_id' => ['required', 'integer', 'different:from_bank_account_id'],
            'transfer_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
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

            foreach (['from_bank_account_id', 'to_bank_account_id'] as $field) {
                $id = $this->input($field);
                if ($id && ! BankAccount::query()->whereKey($id)->exists()) {
                    $validator->errors()->add($field, 'The selected bank account is invalid.');
                }
            }
        });
    }
}
