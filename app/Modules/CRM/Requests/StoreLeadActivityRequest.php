<?php

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activity_type' => ['required', Rule::in(['call', 'email', 'meeting', 'note'])],
            'body' => 'nullable|string|max:2000',
        ];
    }
}
