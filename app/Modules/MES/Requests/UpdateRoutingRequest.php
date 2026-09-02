<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\WorkCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRoutingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'is_active' => 'nullable|boolean',
            'ops' => 'required|array|min:1',
            'ops.*.op_code' => 'required|string|max:30',
            'ops.*.op_name' => 'required|string|max:150',
            'ops.*.work_center_id' => 'required|integer',
            'ops.*.setup_time_minutes' => 'nullable|integer|min:0',
            'ops.*.run_time_minutes' => 'nullable|integer|min:0',
            'ops.*.queue_time_minutes' => 'nullable|integer|min:0',
            'ops.*.standard_output_qty' => 'nullable|numeric|min:0',
            'ops.*.instructions' => 'nullable|string',
            'ops.*.auto_issue_components' => 'nullable|boolean',
            'ops.*.is_rework_destination' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('ops', []) as $i => $op) {
                $workCenterId = $op['work_center_id'] ?? null;
                if ($workCenterId && ! WorkCenter::query()->whereKey($workCenterId)->exists()) {
                    $validator->errors()->add("ops.{$i}.work_center_id", 'The selected work center is invalid.');
                }
            }
        });
    }
}
