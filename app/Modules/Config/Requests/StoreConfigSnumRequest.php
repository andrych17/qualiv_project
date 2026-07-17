<?php

namespace App\Modules\Config\Requests;

use App\Modules\Config\Models\ConfigSnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConfigSnumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique(ConfigSnum::class, 'code'),
            ],
            'last_cnt' => 'required|integer|min:0',
            'wrap_low' => 'required|integer|min:0',
            'wrap_high' => 'required|integer|gte:wrap_low',
            'step_cnt' => 'required|integer|min:1',
            'descr' => 'nullable|string|max:255',
            'status_code' => 'required|in:A,I',
        ];
    }
}
