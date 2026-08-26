<?php

namespace App\Modules\HCM\Requests;

use App\Modules\HCM\Models\Job;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\HCM\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_id' => ['required', 'integer'],
            'org_unit_id' => ['required', 'integer'],
            'reports_to_position_id' => ['nullable', 'integer'],
            'headcount_cap' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $jobId = $this->input('job_id');
            if ($jobId && ! Job::query()->whereKey($jobId)->exists()) {
                $validator->errors()->add('job_id', 'The selected job is invalid.');
            }

            $orgUnitId = $this->input('org_unit_id');
            if ($orgUnitId && ! OrgUnit::query()->whereKey($orgUnitId)->exists()) {
                $validator->errors()->add('org_unit_id', 'The selected department is invalid.');
            }

            $reportsTo = $this->input('reports_to_position_id');
            if ($reportsTo && ! Position::query()->whereKey($reportsTo)->exists()) {
                $validator->errors()->add('reports_to_position_id', 'The selected manager position is invalid.');
            }
        });
    }
}
