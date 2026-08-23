<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\TaxPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTaxPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'obligation_type' => ['required', 'string', 'in:'.implode(',', TaxPeriod::OBLIGATIONS)],
            'masa_pajak' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }
        });
    }
}
