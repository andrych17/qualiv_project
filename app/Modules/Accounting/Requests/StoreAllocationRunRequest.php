<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAllocationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fiscal_period_id' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $periodId = $this->input('fiscal_period_id');
            $companyId = $this->route('rule')?->company_id;
            if ($periodId && ! FiscalPeriod::query()->whereKey($periodId)->where('company_id', $companyId)->exists()) {
                $validator->errors()->add('fiscal_period_id', 'The selected fiscal period is invalid for this rule\'s company.');
            }
        });
    }
}
