<?php

namespace App\Modules\Central\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'price_monthly' => 'required|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'is_active' => 'nullable|boolean',
            'module_codes' => 'nullable|array',
            'module_codes.*' => 'string',
        ];
    }
}
