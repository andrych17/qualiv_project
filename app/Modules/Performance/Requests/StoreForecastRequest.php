<?php

namespace App\Modules\Performance\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\Budget;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Structure/existence checks only — the budget-xor-kpi rule is a business rule enforced (and re-checked) in ForecastService::assertXor(). */
class StoreForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'budget_id' => 'nullable|integer',
            'kpi_id' => 'nullable|integer',
            'subject_type' => 'required_without:budget_id|nullable|in:company,org_unit,employee',
            'subject_id' => 'nullable|integer',
            'period_id' => 'required|integer',
            'notes' => 'nullable|string|max:500',
            'lines' => 'nullable|array',
            'lines.*.period_id' => 'required_with:lines|integer',
            'lines.*.forecast_value' => 'required_with:lines|numeric',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $hasBudget = ! empty($this->input('budget_id'));
            $hasKpi = ! empty($this->input('kpi_id'));
            if ($hasBudget === $hasKpi) {
                $validator->errors()->add('budget_id', 'Link this forecast to exactly one of a budget or a KPI.');
            }

            if ($hasBudget && ! Budget::query()->whereKey($this->input('budget_id'))->exists()) {
                $validator->errors()->add('budget_id', 'The selected budget is invalid.');
            }

            if ($hasKpi && ! KpiDefinition::query()->whereKey($this->input('kpi_id'))->exists()) {
                $validator->errors()->add('kpi_id', 'The selected KPI is invalid.');
            }

            if (! $hasBudget) {
                $subjectType = $this->input('subject_type');
                $subjectId = $this->input('subject_id');
                if ($subjectType !== 'company' && empty($subjectId)) {
                    $validator->errors()->add('subject_id', 'A subject is required for this subject level.');
                } elseif ($subjectId) {
                    $exists = match ($subjectType) {
                        'org_unit' => OrgUnit::query()->whereKey($subjectId)->exists(),
                        'employee' => Employee::query()->whereKey($subjectId)->exists(),
                        default => true,
                    };
                    if (! $exists) {
                        $validator->errors()->add('subject_id', 'The selected subject is invalid.');
                    }
                }
            }

            $periodId = $this->input('period_id');
            if ($periodId && ! Period::query()->whereKey($periodId)->exists()) {
                $validator->errors()->add('period_id', 'The selected period is invalid.');
            }

            foreach ($this->input('lines', []) as $index => $line) {
                if (! empty($line['period_id']) && ! Period::query()->whereKey($line['period_id'])->exists()) {
                    $validator->errors()->add("lines.{$index}.period_id", 'The selected period is invalid.');
                }
            }
        });
    }
}
