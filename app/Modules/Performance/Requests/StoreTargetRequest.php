<?php

namespace App\Modules\Performance\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Structure/existence checks only — "KPI must be active" and uniqueness are business rules enforced in TargetService. */
class StoreTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kpi_id' => 'required|integer',
            'subject_type' => 'required|in:company,org_unit,employee',
            'subject_id' => 'required_unless:subject_type,company|nullable|integer',
            'period_id' => 'required|integer',
            'target_value' => 'required|numeric',
            'stretch_value' => 'nullable|numeric',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $kpiId = $this->input('kpi_id');
            if ($kpiId && ! KpiDefinition::query()->whereKey($kpiId)->exists()) {
                $validator->errors()->add('kpi_id', 'The selected KPI is invalid.');
            }

            $periodId = $this->input('period_id');
            if ($periodId && ! Period::query()->whereKey($periodId)->exists()) {
                $validator->errors()->add('period_id', 'The selected period is invalid.');
            }

            $subjectType = $this->input('subject_type');
            $subjectId = $this->input('subject_id');
            if ($subjectId) {
                $exists = match ($subjectType) {
                    'org_unit' => OrgUnit::query()->whereKey($subjectId)->exists(),
                    'employee' => Employee::query()->whereKey($subjectId)->exists(),
                    default => true,
                };
                if (! $exists) {
                    $validator->errors()->add('subject_id', 'The selected subject is invalid.');
                }
            }
        });
    }
}
