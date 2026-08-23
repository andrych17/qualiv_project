<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3F — CSV upload + explicit column mapping (see BankStatementImportService docblock for why the mapping isn't guessed). */
class StoreBankStatementImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'date_column' => ['required', 'integer', 'min:0'],
            'description_column' => ['required', 'integer', 'min:0'],
            'amount_column' => ['required', 'integer', 'min:0'],
            'reference_column' => ['nullable', 'integer', 'min:0'],
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
        });
    }
}
