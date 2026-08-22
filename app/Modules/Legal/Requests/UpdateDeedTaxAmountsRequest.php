<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeedTaxAmountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_amount' => 'required|numeric|min:0',
            'njop_amount' => 'nullable|numeric|min:0',
            'rate' => 'required|numeric|min:0|max:100',
            'npoptkp_applied' => 'nullable|numeric|min:0',
        ];
    }
}
