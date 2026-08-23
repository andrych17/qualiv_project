<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CoretaxExportBatch;
use App\Modules\Accounting\Models\TaxPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateCoretaxExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'tax_period_id' => ['required', 'integer'],
            'batch_type' => ['required', 'string', 'in:'.implode(',', CoretaxExportBatch::TYPES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            $periodId = $this->input('tax_period_id');
            if ($periodId && ! TaxPeriod::query()->whereKey($periodId)->exists()) {
                $validator->errors()->add('tax_period_id', 'The selected tax period is invalid.');
            }
        });
    }
}
