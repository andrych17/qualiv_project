<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CostCenter;
use App\Modules\Accounting\Models\Currency;
use App\Modules\Accounting\Requests\Concerns\ValidatesRecurrenceRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRecurringJournalTemplateRequest extends FormRequest
{
    use ValidatesRecurrenceRule;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'memo' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['required', 'string', 'size:3'],
            'recurrence_rule' => ['required', 'string', 'max:255'],
            'anchor_date' => ['required', 'date'],
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
            $this->assertValidRecurrenceRule($validator, $this->input('recurrence_rule'), $this->input('anchor_date'));

            $companyId = $this->route('template')?->company_id;

            $currencyCode = $this->input('currency_code');
            if ($currencyCode && ! Currency::query()->where('code', $currencyCode)->where('is_enabled', true)->exists()) {
                $validator->errors()->add('currency_code', 'The selected currency is not enabled.');
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $accountId = $line['account_id'] ?? null;
                if ($accountId && ! Account::query()->whereKey($accountId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add("lines.{$i}.account_id", 'The selected account is invalid for this company.');
                }

                $costCenterId = $line['cost_center_id'] ?? null;
                if ($costCenterId && ! CostCenter::query()->whereKey($costCenterId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add("lines.{$i}.cost_center_id", 'The selected cost center is invalid for this company.');
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
