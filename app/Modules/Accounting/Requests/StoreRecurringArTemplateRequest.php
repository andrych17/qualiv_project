<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Requests\Concerns\ValidatesRecurrenceRule;
use App\Modules\CRM\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRecurringArTemplateRequest extends FormRequest
{
    use ValidatesRecurrenceRule;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'partner_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'currency_code' => ['required', 'string', 'size:3'],
            'invoice_type' => ['required', 'string', 'in:'.implode(',', ArInvoice::TYPES)],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'recurrence_rule' => ['required', 'string', 'max:255'],
            'anchor_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_code_id' => ['nullable', 'integer'],
            'lines.*.revenue_account_id' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->assertValidRecurrenceRule($validator, $this->input('recurrence_rule'), $this->input('anchor_date'));

            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            $partnerId = $this->input('partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('partner_id', 'The selected customer is invalid.');
            }

            $currencyCode = $this->input('currency_code');
            if ($currencyCode && ! Currency::query()->where('code', $currencyCode)->where('is_enabled', true)->exists()) {
                $validator->errors()->add('currency_code', 'The selected currency is not enabled.');
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $taxCodeId = $line['tax_code_id'] ?? null;
                if ($taxCodeId && ! TaxCode::query()->whereKey($taxCodeId)->exists()) {
                    $validator->errors()->add("lines.{$i}.tax_code_id", 'The selected tax code is invalid.');
                }

                $revenueAccountId = $line['revenue_account_id'] ?? null;
                if ($revenueAccountId && ! Account::query()->whereKey($revenueAccountId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add("lines.{$i}.revenue_account_id", 'The selected revenue account is invalid for this company.');
                }
            }
        });
    }
}
