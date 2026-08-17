<?php

namespace App\Modules\Central\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'plan_code' => 'required|string|exists:central_plans,code',
            'contact_name' => 'nullable|string|max:150',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'billing_address' => 'nullable|string|max:1000',
        ];
    }
}
