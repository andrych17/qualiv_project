<?php

namespace App\Modules\MES\Requests;

use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\WorkCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMachineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_center_id' => 'required|integer',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:150',
            'status' => ['required', Rule::in([
                Machine::STATUS_RUNNING, Machine::STATUS_IDLE, Machine::STATUS_DOWN, Machine::STATUS_MAINTENANCE,
                Machine::STATUS_SETUP, Machine::STATUS_WAITING_MATERIAL, Machine::STATUS_WAITING_OPERATOR, Machine::STATUS_WAITING_QC,
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $machine = $this->route('machine');

            if (Machine::query()->where('code', $this->input('code'))->where('id', '!=', $machine?->id)->exists()) {
                $validator->errors()->add('code', 'This code is already in use.');
            }

            $workCenterId = $this->input('work_center_id');
            if ($workCenterId && ! WorkCenter::query()->whereKey($workCenterId)->exists()) {
                $validator->errors()->add('work_center_id', 'The selected work center is invalid.');
            }
        });
    }
}
