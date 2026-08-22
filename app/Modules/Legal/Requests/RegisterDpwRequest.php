<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDpwRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dpw_reg_number' => 'required|string|max:100',
            'dpw_registered_at' => 'nullable|date',
        ];
    }
}
