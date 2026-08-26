<?php

namespace App\Modules\HCM\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->input('employee_id');
            if ($employeeId && ! Employee::query()->whereKey($employeeId)->exists()) {
                $validator->errors()->add('employee_id', 'The selected employee is invalid.');
            }

            $leaveTypeId = $this->input('leave_type_id');
            if ($leaveTypeId && ! LeaveType::query()->whereKey($leaveTypeId)->exists()) {
                $validator->errors()->add('leave_type_id', 'The selected leave type is invalid.');
            }
        });
    }
}
