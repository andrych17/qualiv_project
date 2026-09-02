<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\ProcessParameter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3I — "COMPLETE PHASE" action payload: one reading per this phase's process parameters. */
class StoreBatchPhaseCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'readings' => 'nullable|array',
            'readings.*.process_parameter_id' => 'required_with:readings|integer',
            'readings.*.value' => 'required_with:readings|numeric',
            'location_id' => 'nullable|integer',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('readings', []) as $i => $reading) {
                $parameterId = $reading['process_parameter_id'] ?? null;
                if ($parameterId && ! ProcessParameter::query()->whereKey($parameterId)->exists()) {
                    $validator->errors()->add("readings.{$i}.process_parameter_id", 'The selected parameter is invalid.');
                }
            }
        });
    }
}
