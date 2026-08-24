<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3J — grid save: cells are scoped to one cost center at a time; every account_id/fiscal_period_id must belong to the target Budget's own company/fiscal year (closure-based check — this codebase's `exists:SCHEMA.table,column` rule doesn't work against schema-qualified tables). */
class StoreBudgetGridRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cost_center_id' => ['nullable', 'integer'],
            'cells' => ['present', 'array'],
            'cells.*.account_id' => ['required', 'integer'],
            'cells.*.fiscal_period_id' => ['required', 'integer'],
            'cells.*.amount' => ['required', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $budget = $this->route('budget');

            $costCenterId = $this->input('cost_center_id');
            if ($costCenterId && ! CostCenter::query()->whereKey($costCenterId)->where('company_id', $budget->company_id)->exists()) {
                $validator->errors()->add('cost_center_id', 'The selected cost center is invalid for this company.');
            }

            foreach ((array) $this->input('cells', []) as $i => $cell) {
                $accountId = $cell['account_id'] ?? null;
                if ($accountId && ! Account::query()->whereKey($accountId)->where('company_id', $budget->company_id)->exists()) {
                    $validator->errors()->add("cells.{$i}.account_id", 'Invalid account for this company.');
                }

                $periodId = $cell['fiscal_period_id'] ?? null;
                if ($periodId && ! FiscalPeriod::query()->whereKey($periodId)->where('fiscal_year_id', $budget->fiscal_year_id)->exists()) {
                    $validator->errors()->add("cells.{$i}.fiscal_period_id", 'Invalid period for this budget\'s fiscal year.');
                }
            }
        });
    }
}
