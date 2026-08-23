<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\FiscalPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3C manual journal entry — header + N balanced lines. Balance itself is checked at post() time, not here (a draft may be transiently unbalanced). */
class StoreJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'fiscal_period_id' => ['required', 'integer'],
            'journal_date' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3'],
            'memo' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.cost_center_id' => ['nullable', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            $periodId = $this->input('fiscal_period_id');
            if ($periodId && ! FiscalPeriod::query()->whereKey($periodId)->exists()) {
                $validator->errors()->add('fiscal_period_id', 'The selected fiscal period is invalid.');
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $accountId = $line['account_id'] ?? null;
                if ($accountId && ! Account::query()->whereKey($accountId)->exists()) {
                    $validator->errors()->add("lines.{$i}.account_id", 'The selected account is invalid.');
                }

                $costCenterId = $line['cost_center_id'] ?? null;
                if ($costCenterId && ! CostCenter::query()->whereKey($costCenterId)->exists()) {
                    $validator->errors()->add("lines.{$i}.cost_center_id", 'The selected cost center is invalid.');
                }

                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);
                if ($debit > 0 && $credit > 0) {
                    $validator->errors()->add("lines.{$i}.debit", 'A line cannot have both a debit and a credit amount.');
                }
                if ($debit <= 0 && $credit <= 0) {
                    $validator->errors()->add("lines.{$i}.debit", 'Each line needs a debit or a credit amount.');
                }
            }
        });
    }
}
