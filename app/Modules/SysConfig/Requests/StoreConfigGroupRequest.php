<?php

namespace App\Modules\SysConfig\Requests;

use App\Modules\SysConfig\Models\ConfigGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreConfigGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash',
            'descr' => 'nullable|string|max:500',
            'status_code' => 'required|in:A,I',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $code = $this->input('code');
            if ($code && ConfigGroup::query()->where('app_code', 'NUSAEVO')->where('code', $code)->exists()) {
                $validator->errors()->add('code', 'The code has already been taken.');
            }
        });
    }
}
