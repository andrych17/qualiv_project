<?php

namespace App\Modules\Performance\Requests;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\Performance\Models\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'subject_type' => 'required|in:company,org_unit,employee',
            'subject_id' => 'required_unless:subject_type,company|nullable|integer',
            'fiscal_year' => 'required|integer|min:2000|max:2100',
            'fiscal_quarter' => 'nullable|integer|min:1|max:4',
            'owner_id' => 'nullable|integer',
            'notes' => 'nullable|string|max:500',
            'lines' => 'nullable|array',
            'lines.*.category' => 'required_with:lines|string|max:100',
            'lines.*.period_id' => 'required_with:lines|integer',
            'lines.*.amount_planned' => 'required_with:lines|numeric',
            'lines.*.notes' => 'nullable|string|max:500',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $subjectType = $this->input('subject_type');
            $subjectId = $this->input('subject_id');
            if ($subjectId) {
                $exists = match ($subjectType) {
                    'org_unit' => OrgUnit::query()->whereKey($subjectId)->exists(),
                    'employee' => Employee::query()->whereKey($subjectId)->exists(),
                    default => true,
                };
                if (! $exists) {
                    $validator->errors()->add('subject_id', 'The selected subject is invalid.');
                }
            }

            foreach ($this->input('lines', []) as $index => $line) {
                if (! empty($line['period_id']) && ! Period::query()->whereKey($line['period_id'])->exists()) {
                    $validator->errors()->add("lines.{$index}.period_id", 'The selected period is invalid.');
                }
            }
        });
    }
}
