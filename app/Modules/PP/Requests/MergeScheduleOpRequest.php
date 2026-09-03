<?php

namespace App\Modules\PP\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeScheduleOpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_id' => 'required|integer',
        ];
    }
}
