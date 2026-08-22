<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteFieldVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'checklist_result' => 'nullable|array',
            'checklist_result.*.label' => 'required|string',
            'checklist_result.*.done' => 'required|boolean',
            'checklist_result.*.note' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
