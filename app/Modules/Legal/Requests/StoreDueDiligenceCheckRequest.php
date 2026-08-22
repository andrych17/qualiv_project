<?php

namespace App\Modules\Legal\Requests;

use App\Modules\Legal\Models\DueDiligenceCheck;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDueDiligenceCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'check_type' => ['required', Rule::in(DueDiligenceCheck::TYPES)],
        ];
    }
}
