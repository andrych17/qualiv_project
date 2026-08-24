<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDepreciationRunRequest extends FormRequest
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
            if ($periodId && ! FiscalPeriod::query()->whereKey($periodId)->exists()) {
                $validator->errors()->add('fiscal_period_id', 'The selected fiscal period is invalid.');
            }
        });
    }
}
