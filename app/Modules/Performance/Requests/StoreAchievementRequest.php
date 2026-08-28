<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\Achievement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAchievementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'badge_id' => 'required|integer|exists:PERF.badge_definitions,id',
            'subject_type' => ['required', Rule::in([Achievement::SUBJECT_COMPANY, Achievement::SUBJECT_ORG_UNIT, Achievement::SUBJECT_EMPLOYEE])],
            'subject_id' => 'required_unless:subject_type,'.Achievement::SUBJECT_COMPANY.'|nullable|integer',
            'kpi_id' => 'nullable|integer|exists:PERF.kpi_definitions,id',
            'okr_id' => 'nullable|integer|exists:PERF.okr_objectives,id',
            'period_id' => 'nullable|integer|exists:PERF.periods,id',
        ];
    }
}
