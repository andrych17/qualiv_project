<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\Station;
use App\Modules\MES\Models\WorkCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_center_id' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:150',
        ];
    }

    /** Schema-qualified tables (MES.*) can't be checked via `exists:`/`unique:` — see PP's StoreResourceRequest. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (Station::query()->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'This code is already in use.');
            }

            if (! $this->input('work_center_id') && ! $this->input('machine_id')) {
                $validator->errors()->add('work_center_id', 'A station must belong to a work center or a machine.');
            }

            $workCenterId = $this->input('work_center_id');
            if ($workCenterId && ! WorkCenter::query()->whereKey($workCenterId)->exists()) {
                $validator->errors()->add('work_center_id', 'The selected work center is invalid.');
            }

            $machineId = $this->input('machine_id');
            if ($machineId && ! Machine::query()->whereKey($machineId)->exists()) {
                $validator->errors()->add('machine_id', 'The selected machine is invalid.');
            }
        });
    }
}
