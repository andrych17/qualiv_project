<?php

namespace App\Modules\PP\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMpsLineQtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'planned_qty' => 'required|numeric|min:0',
        ];
    }
}
