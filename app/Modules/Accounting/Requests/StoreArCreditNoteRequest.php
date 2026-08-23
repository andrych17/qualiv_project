<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3D — create + immediately post a credit note against a specific invoice (v1 UI scope, see ArCreditNoteService docblock). */
class StoreArCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ar_invoice_id' => ['required', 'integer'],
            'credit_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
            'revenue_account_id' => ['required', 'integer'],
        ];
    }

    // exists:ACCOUNTING.*,id can't be used — Laravel's exists rule parses the dot as
    // connection.table, not schema.table (see DMS\Requests\StoreFolderRequest).
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $invoiceId = $this->input('ar_invoice_id');
            if ($invoiceId && ! ArInvoice::query()->whereKey($invoiceId)->exists()) {
                $validator->errors()->add('ar_invoice_id', 'The selected invoice is invalid.');
            }

            $revenueAccountId = $this->input('revenue_account_id');
            if ($revenueAccountId && ! Account::query()->whereKey($revenueAccountId)->exists()) {
                $validator->errors()->add('revenue_account_id', 'The selected revenue account is invalid.');
            }
        });
    }
}
