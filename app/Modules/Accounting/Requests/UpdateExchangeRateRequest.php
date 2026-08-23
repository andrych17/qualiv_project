<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\ExchangeRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3L — rate value/date corrections. company_id/currency_code are fixed at creation (route-bound). */
class UpdateExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rate_to_base' => ['required', 'numeric', 'min:0.000001'],
            'effective_date' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ExchangeRate $rate */
            $rate = $this->route('exchangeRate');

            if ($rate && ExchangeRate::query()
                ->where('company_id', $rate->company_id)
                ->where('currency_code', $rate->currency_code)
                ->where('effective_date', $this->input('effective_date'))
                ->whereKeyNot($rate->id)
                ->exists()
            ) {
                $validator->errors()->add('effective_date', 'A rate for this currency on this date already exists.');
            }
        });
    }
}
