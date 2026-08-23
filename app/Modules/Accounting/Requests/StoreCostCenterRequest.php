<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCostCenterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'parent_cost_center_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            $parentId = $this->input('parent_cost_center_id');
            if ($parentId && ! CostCenter::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_cost_center_id', 'The selected parent cost center is invalid.');
            }

            if (CostCenter::query()->where('company_id', $companyId)->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'This code is already used in the selected company.');
            }
        });
    }
}
