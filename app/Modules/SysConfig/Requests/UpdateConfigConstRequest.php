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
            'appl_id' => 'nullable|string|max:20',
            'group_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'const_group' => 'required|string|max:100',
            'group_code' => 'required|string|max:100',
            'value' => 'nullable|string',
            'value_type' => 'required|in:text,number,bool,date',
            'seq' => 'required|integer|min:0|max:9999',
            'str1' => 'nullable|string|max:255',
            'str2' => 'nullable|string|max:255',
            'num1' => 'nullable|numeric',
            'num2' => 'nullable|numeric',
            'note1' => 'nullable|string|max:1000',
            'effective_date' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
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
                ->where('appl_id', $this->input('appl_id'))
                ->where('group_id', $this->input('group_id'))
                ->where('user_id', $this->input('user_id'))
                ->where('is_active', true)
                ->when($id, fn ($q) => $q->where('id', '!=', $id))
                ->exists()) {
                $validator->errors()->add('group_code', 'This const key already exists in this scope.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'appl_id' => $this->filled('appl_id') ? strtoupper((string) $this->input('appl_id')) : null,
            'group_id' => $this->filled('group_id') ? (int) $this->input('group_id') : null,
            'user_id' => $this->filled('user_id') ? (int) $this->input('user_id') : null,
            'value' => $this->filled('value') ? $this->input('value') : null,
            'value_type' => $this->input('value_type') ?: 'text',
            'effective_date' => $this->filled('effective_date') ? $this->input('effective_date') : null,
        ]);
    }
}
