<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\Achievement;
use App\Modules\Performance\Models\BadgeDefinition;
use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\OkrObjective;
use App\Modules\Performance\Models\Period;
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
            'badge_id' => ['required', 'integer', Rule::exists(BadgeDefinition::class, 'id')],
            'subject_type' => ['required', Rule::in([Achievement::SUBJECT_COMPANY, Achievement::SUBJECT_ORG_UNIT, Achievement::SUBJECT_EMPLOYEE])],
            'subject_id' => 'required_unless:subject_type,'.Achievement::SUBJECT_COMPANY.'|nullable|integer',
            'kpi_id' => ['nullable', 'integer', Rule::exists(KpiDefinition::class, 'id')],
            'okr_id' => ['nullable', 'integer', Rule::exists(OkrObjective::class, 'id')],
            'period_id' => ['nullable', 'integer', Rule::exists(Period::class, 'id')],
        ];
    }
}
