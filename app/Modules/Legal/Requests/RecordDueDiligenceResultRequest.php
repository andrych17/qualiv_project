<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordDueDiligenceResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['clear', 'flagged'])],
            'result_notes' => 'nullable|string|max:2000',
        ];
    }
}
