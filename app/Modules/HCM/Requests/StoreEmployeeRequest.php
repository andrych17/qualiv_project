<?php

namespace App\Modules\HCM\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\HCM\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_no' => ['nullable', 'string', 'max:30'],
            'full_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'in:male,female,other'],
            'nik' => ['nullable', 'string', 'size:16'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'bpjs_kesehatan_no' => ['nullable', 'string', 'max:30'],
            'bpjs_ketenagakerjaan_no' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'marital_status' => ['nullable', 'string', 'in:single,married,divorced,widowed'],
            'dependents_count' => ['nullable', 'integer', 'min:0', 'max:10'],
            'religion' => ['nullable', 'string', 'max:30'],
            'hire_date' => ['required', 'date'],
            'position_id' => ['nullable', 'integer'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_no' => ['nullable', 'string', 'max:50'],
            'bank_account_holder_name' => ['nullable', 'string', 'max:150'],

            // Minimal hire contract fields
            'contract_type' => ['nullable', 'string', 'in:'.EmploymentContract::TYPE_PKWT.','.EmploymentContract::TYPE_PKWTT],
            'contract_end_date' => ['nullable', 'required_if:contract_type,'.EmploymentContract::TYPE_PKWT, 'date', 'after:hire_date'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'work_location' => ['nullable', 'string', 'max:150'],
            'probation_end_date' => ['nullable', 'date', 'after:hire_date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employeeNo = $this->input('employee_no');
            if ($employeeNo && Employee::query()->where('employee_no', $employeeNo)->exists()) {
                $validator->errors()->add('employee_no', 'The employee number has already been taken.');
            }

            $positionId = $this->input('position_id');
            if ($positionId && ! Position::query()->whereKey($positionId)->exists()) {
                $validator->errors()->add('position_id', 'The selected position is invalid.');
            }
        });
    }
}
