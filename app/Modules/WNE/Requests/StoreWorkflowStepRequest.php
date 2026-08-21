<?php

namespace App\Modules\WNE\Requests;

use App\Modules\WNE\Models\WrkflowStep;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'step_code' => 'required|string|max:50|regex:/^[a-z0-9_]+$/',
            'type' => 'required|string|in:'.implode(',', [
                WrkflowStep::TYPE_APPROVAL,
                WrkflowStep::TYPE_TASK,
                WrkflowStep::TYPE_CONDITION,
                WrkflowStep::TYPE_PARALLEL_SPLIT,
                WrkflowStep::TYPE_PARALLEL_JOIN,
                WrkflowStep::TYPE_WEBHOOK_CALL,
                WrkflowStep::TYPE_WAIT_FOR_CALLBACK,
                WrkflowStep::TYPE_NOTIFY,
            ]),
            'config' => 'nullable|array',
            'webhook_auth_headers' => 'nullable|array',
            'pos_x' => 'nullable|numeric',
            'pos_y' => 'nullable|numeric',
            'is_entry_step' => 'nullable|boolean',
        ];
    }
}
