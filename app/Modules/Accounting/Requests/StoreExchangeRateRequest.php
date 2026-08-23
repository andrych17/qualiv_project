<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Models\ExchangeRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3L — one company's rate-to-base for a currency, effective on a date. */
class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'currency_code' => ['required', 'string', 'size:3'],
            'rate_to_base' => ['required', 'numeric', 'min:0.000001'],
            'effective_date' => ['required', 'date'],
        ];
    }

    // exists:ACCOUNTING.*,id can't be used — Laravel's exists rule parses the dot as
    // connection.table, not schema.table (see DMS\Requests\StoreFolderRequest).
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            $company = $companyId ? Company::query()->find($companyId) : null;
            if ($companyId && $company === null) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            $currencyCode = $this->input('currency_code');
            if ($currencyCode && ! Currency::query()->where('code', $currencyCode)->where('is_enabled', true)->exists()) {
                $validator->errors()->add('currency_code', 'The selected currency is not enabled.');
            }

            if ($company && $currencyCode && $currencyCode === $company->base_currency) {
                $validator->errors()->add('currency_code', "This is {$company->legal_name}'s base currency — it never needs a rate.");
            }

            if ($companyId && $currencyCode && $this->input('effective_date')
                && ExchangeRate::query()
                    ->where('company_id', $companyId)
                    ->where('currency_code', $currencyCode)
                    ->where('effective_date', $this->input('effective_date'))
                    ->exists()
            ) {
                $validator->errors()->add('effective_date', 'A rate for this currency on this date already exists — edit it instead.');
            }
        });
    }
}
