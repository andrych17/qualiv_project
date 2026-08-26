<?php

namespace App\Modules\HCM\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmploymentContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer'],
            'contract_type' => ['required', 'string', 'in:'.EmploymentContract::TYPE_PKWT.','.EmploymentContract::TYPE_PKWTT],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'required_if:contract_type,'.EmploymentContract::TYPE_PKWT, 'date', 'after:start_date'],
            'base_salary' => ['required', 'numeric', 'min:0'],
            'work_location' => ['nullable', 'string', 'max:150'],
            'probation_end_date' => ['nullable', 'date', 'after:start_date'],
            'status' => ['nullable', 'string', 'in:active,expired,terminated,renewed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeId = $this->input('employee_id');
            if ($employeeId && ! Employee::query()->whereKey($employeeId)->exists()) {
                $validator->errors()->add('employee_id', 'The selected employee is invalid.');
            }
        });
    }
}
