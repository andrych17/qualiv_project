<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideDueDiligenceCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'justification' => 'required|string|max:2000',
        ];
    }
}
