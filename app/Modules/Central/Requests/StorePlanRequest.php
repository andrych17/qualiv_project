<?php

namespace App\Modules\Central\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|unique:central_plans,code',
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'price_monthly' => 'required|numeric|min:0',
            'price_annual' => 'nullable|numeric|min:0',
            'billing_cycle' => 'nullable|string|in:monthly,annual',
            'currency' => 'nullable|string|size:3',
            'is_active' => 'nullable|boolean',
            'module_codes' => 'nullable|array',
            'module_codes.*' => 'string',
        ];
    }
}
