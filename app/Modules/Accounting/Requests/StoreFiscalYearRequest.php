<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreFiscalYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'digits:4'],
            'start_date' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            if (FiscalYear::query()->where('company_id', $companyId)->where('year', $this->input('year'))->exists()) {
                $validator->errors()->add('year', 'This company already has a fiscal year for this year.');
            }
        });
    }
}
