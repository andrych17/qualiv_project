<?php

namespace App\Modules\SysConfig\Requests;

use App\Modules\SysConfig\Models\ConfigSnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConfigSnumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var ConfigSnum|null $snum */
        $snum = $this->route('configSnum');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique(ConfigSnum::class, 'code')->ignore($snum?->id),
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
