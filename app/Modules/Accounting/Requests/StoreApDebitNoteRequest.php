<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3E — create + immediately post a debit note against a specific bill (v1 UI scope, see ApDebitNoteService docblock). */
class StoreApDebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ap_bill_id' => ['required', 'integer'],
            'debit_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
            'expense_account_id' => ['required', 'integer'],
        ];
    }

    // exists:ACCOUNTING.*,id can't be used — Laravel's exists rule parses the dot as
    // connection.table, not schema.table (see DMS\Requests\StoreFolderRequest).
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $billId = $this->input('ap_bill_id');
            if ($billId && ! ApBill::query()->whereKey($billId)->exists()) {
                $validator->errors()->add('ap_bill_id', 'The selected bill is invalid.');
            }

            $expenseAccountId = $this->input('expense_account_id');
            if ($expenseAccountId && ! Account::query()->whereKey($expenseAccountId)->exists()) {
                $validator->errors()->add('expense_account_id', 'The selected account is invalid.');
            }
        });
    }
}
