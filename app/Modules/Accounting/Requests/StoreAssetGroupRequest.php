<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAssetGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'is_building' => ['boolean'],
            'fiscal_useful_life_months' => ['required', 'integer', 'min:1'],
            'fiscal_straight_line_rate' => ['required', 'numeric', 'min:0.0001', 'max:1'],
            'fiscal_declining_rate' => ['nullable', 'numeric', 'min:0.0001', 'max:1'],
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
