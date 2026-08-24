<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\PayrollComponentGlMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3S — "employer_cost needs payable_account_id too" is a service-level rule (PayrollComponentGlMappingService::assertPayableRequiredForEmployerCost), not here, same split AllocationRuleService uses for its own cross-field rules. */
class StorePayrollComponentGlMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'component_code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_]+$/'],
            'component_label' => ['required', 'string', 'max:100'],
            'component_type' => ['required', 'string', 'in:'.implode(',', PayrollComponentGlMapping::TYPES)],
            'gl_account_id' => ['required', 'integer'],
            'payable_account_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            foreach (['gl_account_id', 'payable_account_id'] as $field) {
                $accountId = $this->input($field);
                if ($accountId && ! Account::query()->whereKey($accountId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add($field, 'The selected account is invalid for this company.');
                }
            }

            $companyId = $this->input('company_id');
            $code = $this->input('component_code');
            if ($companyId && $code && PayrollComponentGlMapping::query()->where('company_id', $companyId)->where('component_code', $code)->exists()) {
                $validator->errors()->add('component_code', 'This company already has a mapping for this component code — edit that one instead.');
            }
        });
    }
}
