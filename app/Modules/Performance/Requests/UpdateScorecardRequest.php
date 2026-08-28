<?php

namespace App\Modules\Performance\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Perspective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateScorecardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'subject_type' => 'required|in:company,org_unit,employee',
            'subject_id' => 'required_unless:subject_type,company|nullable|integer',
            'period_id' => 'required|integer',
            'items' => 'nullable|array',
            'items.*.perspective_id' => 'required_with:items|integer',
            'items.*.kpi_id' => 'nullable|integer',
            'items.*.okr_id' => 'nullable|integer',
            'items.*.weight' => 'required_with:items|numeric|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
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

            $periodId = $this->input('period_id');
            if ($periodId && ! Period::query()->whereKey($periodId)->exists()) {
                $validator->errors()->add('period_id', 'The selected period is invalid.');
            }

            foreach ($this->input('items', []) as $index => $item) {
                if (! empty($item['perspective_id']) && ! Perspective::query()->whereKey($item['perspective_id'])->exists()) {
                    $validator->errors()->add("items.{$index}.perspective_id", 'The selected perspective is invalid.');
                }
                if (! empty($item['kpi_id']) && ! KpiDefinition::query()->whereKey($item['kpi_id'])->exists()) {
                    $validator->errors()->add("items.{$index}.kpi_id", 'The selected KPI is invalid.');
                }
                if (! empty($item['okr_id']) && ! OkrObjective::query()->whereKey($item['okr_id'])->exists()) {
                    $validator->errors()->add("items.{$index}.okr_id", 'The selected OKR objective is invalid.');
                }
            }
        });
    }
}
