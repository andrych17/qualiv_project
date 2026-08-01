<?php

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper((string) $this->input('code')),
            'lead_id' => $this->input('lead_id') === '' ? null : $this->input('lead_id'),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:20|alpha_dash',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:active,archived',
            'lead_id' => 'nullable|integer|exists:users,id',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Project|null $project */
            $project = $this->route('project');
            if (Project::query()
                ->where('code', $this->input('code'))
                ->when($project, fn ($q) => $q->where('id', '!=', $project->id))
                ->exists()) {
                $validator->errors()->add('code', 'Project code already exists.');
            }
        });
    }
}
