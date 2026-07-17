<?php

namespace App\Modules\Legal\Requests;

use App\Modules\Legal\Models\LegalCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLegalCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'nullable|string|max:50|alpha_dash',
            'title' => 'required|string|max:255',
            'status' => 'required|in:open,pending,closed',
            'notes' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $code = $this->input('code');
            if ($code && LegalCase::query()->where('code', $code)->exists()) {
                $validator->errors()->add('code', 'Case code already exists.');
            }
        });
    }
}
