<?php

namespace App\Modules\WNE\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMsgTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string',
            'variables' => 'nullable|array',
            'variables.*' => 'string',
        ];
    }
}
