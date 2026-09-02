<?php

namespace App\Modules\PP\Requests;

use App\Modules\PP\Models\ScheduleOp;
use App\Modules\PP\Services\SchedulingRuleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PP_SPECS.md §3I — apply a dispatch strategy to one resource's draft queue. */
class ApplySchedulingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::in([
                ScheduleOp::RESOURCE_TYPE_MES_WORK_CENTER,
                ScheduleOp::RESOURCE_TYPE_MES_MACHINE,
                ScheduleOp::RESOURCE_TYPE_MES_STATION,
            ])],
            'resource_ref_id' => 'required|integer',
            // PENDING strategies (§3J-dependent) are rejected here too, not just server-side in
            // the service — the UI never offers them, but a direct POST should still 422 cleanly.
            'strategy' => ['required', Rule::in(SchedulingRuleService::AVAILABLE)],
        ];
    }
}
