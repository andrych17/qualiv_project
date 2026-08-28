<?php

namespace App\Modules\Performance\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Models\OkrObjective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateOkrObjectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cycle_id' => 'required|integer',
            'subject_type' => 'required|in:company,org_unit,employee',
            'subject_id' => 'required_unless:subject_type,company|nullable|integer',
            'objective_text' => 'required|string|max:500',
            'parent_okr_id' => 'nullable|integer',
            'status' => 'nullable|in:on_track,at_risk,off_track,completed',
            'key_results' => 'nullable|array',
            'key_results.*.description' => 'required_with:key_results|string|max:255',
            'key_results.*.metric_type' => 'required_with:key_results|in:numeric,percent,boolean,milestone',
            'key_results.*.start_value' => 'nullable|numeric',
            'key_results.*.current_value' => 'nullable|numeric',
            'key_results.*.target_value' => 'required_with:key_results|numeric',
            'key_results.*.weight' => 'nullable|numeric|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $cycleId = $this->input('cycle_id');
            if ($cycleId && ! OkrCycle::query()->whereKey($cycleId)->exists()) {
                $validator->errors()->add('cycle_id', 'The selected cycle is invalid.');
            }

            $parentId = $this->input('parent_okr_id');
            if ($parentId && ! OkrObjective::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_okr_id', 'The selected parent objective is invalid.');
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
