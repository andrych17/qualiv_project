<?php

namespace App\Modules\Legal\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandoverProtocolBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => 'required|string|max:255',
        ];
    }
}
