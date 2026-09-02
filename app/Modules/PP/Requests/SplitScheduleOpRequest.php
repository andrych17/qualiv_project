<?php

namespace App\Modules\PP\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SplitScheduleOpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'split_at' => 'required|date',
        ];
    }
}
