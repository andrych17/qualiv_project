<?php

namespace App\Modules\Central\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReactivateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:1000',
        ];
    }
}
