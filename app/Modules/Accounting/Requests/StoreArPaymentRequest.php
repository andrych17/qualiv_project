<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\CRM\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3D — record + immediately post a customer payment (the human submitting this form is the review step). */
class StoreArPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'partner_id' => ['required', 'integer'],
            'cash_gl_account_id' => ['required', 'integer'],
            'currency_code' => ['required', 'string', 'size:3'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'memo' => ['nullable', 'string', 'max:255'],
            'applications' => ['nullable', 'array'],
            'applications.*.ar_invoice_id' => ['required_with:applications', 'integer'],
            'applications.*.applied_amount' => ['required_with:applications', 'numeric', 'min:0.01'],
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

            $partnerId = $this->input('partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('partner_id', 'The selected customer is invalid.');
            }

            $cashAccountId = $this->input('cash_gl_account_id');
            if ($cashAccountId && ! Account::query()->whereKey($cashAccountId)->exists()) {
                $validator->errors()->add('cash_gl_account_id', 'The selected cash/bank account is invalid.');
            }

            $currencyCode = $this->input('currency_code');
            if ($currencyCode && ! Currency::query()->where('code', $currencyCode)->where('is_enabled', true)->exists()) {
                $validator->errors()->add('currency_code', 'The selected currency is not enabled.');
            }

            foreach ((array) $this->input('applications', []) as $i => $app) {
                $invoiceId = $app['ar_invoice_id'] ?? null;
                if ($invoiceId && ! ArInvoice::query()->whereKey($invoiceId)->exists()) {
                    $validator->errors()->add("applications.{$i}.ar_invoice_id", 'The selected invoice is invalid.');
                }
            }
        });
    }
}
