<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:100'],
            'is_building' => ['boolean'],
            'fiscal_useful_life_months' => ['required', 'integer', 'min:1'],
            'fiscal_straight_line_rate' => ['required', 'numeric', 'min:0.0001', 'max:1'],
            'fiscal_declining_rate' => ['nullable', 'numeric', 'min:0.0001', 'max:1'],
            'is_active' => ['boolean'],
        ];
    }
}
