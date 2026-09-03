<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\WorkCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProcessPhaseSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phases' => 'required|array|min:1',
            'phases.*.phase_name' => 'required|string|max:150',
            'phases.*.work_center_id' => 'nullable|integer',
            'phases.*.standard_duration_minutes' => 'nullable|integer|min:0',
            'phases.*.parameters' => 'nullable|array',
            'phases.*.parameters.*.parameter_code' => 'required|string|max:50',
            'phases.*.parameters.*.target_value' => 'nullable|numeric',
            'phases.*.parameters.*.min_value' => 'nullable|numeric',
            'phases.*.parameters.*.max_value' => 'nullable|numeric',
            'phases.*.parameters.*.uom_code' => 'nullable|string|max:10',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('phases', []) as $i => $phase) {
                $workCenterId = $phase['work_center_id'] ?? null;
                if ($workCenterId && ! WorkCenter::query()->whereKey($workCenterId)->exists()) {
                    $validator->errors()->add("phases.{$i}.work_center_id", 'The selected work center is invalid.');
                }
            }
        });
    }
}
