<?php

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
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
            'start_date' => $this->input('start_date') === '' ? null : $this->input('start_date'),
            'end_date' => $this->input('end_date') === '' ? null : $this->input('end_date'),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:20|alpha_dash',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:planning,active,on_hold,completed,cancelled',
            'lead_id' => 'nullable|integer|exists:users,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $code = $this->input('code');
            if ($code && Project::query()->where('code', $code)->exists()) {
                $validator->errors()->add('code', 'Project code already exists.');
            }
        });
    }
}
