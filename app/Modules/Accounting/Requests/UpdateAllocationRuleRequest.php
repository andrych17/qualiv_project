<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CostCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAllocationRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'source_account_id' => ['required', 'integer'],
            'source_cost_center_id' => ['nullable', 'integer'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.cost_center_id' => ['required', 'integer'],
            'targets.*.percentage' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->route('rule')?->company_id;

            $sourceAccountId = $this->input('source_account_id');
            if ($sourceAccountId && ! Account::query()->whereKey($sourceAccountId)->where('company_id', $companyId)->exists()) {
                $validator->errors()->add('source_account_id', 'The selected source account is invalid for this company.');
            }

            $sourceCostCenterId = $this->input('source_cost_center_id');
            if ($sourceCostCenterId && ! CostCenter::query()->whereKey($sourceCostCenterId)->where('company_id', $companyId)->exists()) {
                $validator->errors()->add('source_cost_center_id', 'The selected source cost center is invalid for this company.');
            }

            foreach ((array) $this->input('targets', []) as $i => $target) {
                $costCenterId = $target['cost_center_id'] ?? null;
                if ($costCenterId && ! CostCenter::query()->whereKey($costCenterId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add("targets.{$i}.cost_center_id", 'The selected cost center is invalid for this company.');
                }
            }
        });
    }
}
