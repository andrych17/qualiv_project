<?php

namespace App\Modules\WNE\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteWorkflowTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decision' => 'required|string|max:50',
            'comment' => 'nullable|string|max:2000',
        ];
    }
}
