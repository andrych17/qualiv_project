<?php

namespace App\Modules\Central\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module_code' => 'required|string|max:100',
            'price_override' => 'nullable|numeric|min:0',
        ];
    }
}
