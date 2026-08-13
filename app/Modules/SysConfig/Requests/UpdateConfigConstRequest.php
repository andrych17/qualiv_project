<?php

namespace App\Modules\SysConfig\Requests;

use App\Modules\SysConfig\Models\ConfigConst;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateConfigConstRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'const_group' => 'required|string|max:100',
            'group_code' => 'required|string|max:100',
            'seq' => 'required|integer|min:0|max:9999',
            'str1' => 'nullable|string|max:255',
            'str2' => 'nullable|string|max:255',
            'num1' => 'nullable|numeric',
            'num2' => 'nullable|numeric',
            'note1' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ConfigConst|null $const */
            $const = $this->route('configConst');
            $id = $const?->id;

            if (ConfigConst::query()
                ->where('const_group', $this->input('const_group'))
                ->where('group_code', $this->input('group_code'))
                ->when($id, fn ($q) => $q->where('id', '!=', $id))
                ->exists()) {
                $validator->errors()->add('group_code', 'This const key already exists in the group.');
            }
        });
    }
}
