<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\PayrollComponentGlMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePayrollComponentGlMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'component_label' => ['required', 'string', 'max:100'],
            'component_type' => ['required', 'string', 'in:'.implode(',', PayrollComponentGlMapping::TYPES)],
            'gl_account_id' => ['required', 'integer'],
            'payable_account_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->route('mapping')->company_id;

            foreach (['gl_account_id', 'payable_account_id'] as $field) {
                $accountId = $this->input($field);
                if ($accountId && ! Account::query()->whereKey($accountId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add($field, 'The selected account is invalid for this company.');
                }
            }
        });
    }
}
