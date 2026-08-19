<?php

namespace App\Modules\SysConfig\Requests;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreConfigUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'group_ids' => 'nullable|array',
            'group_ids.*' => 'integer',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $email = strtolower((string) $this->input('email'));
            if ($email && User::query()->where('email', $email)->exists()) {
                $validator->errors()->add('email', 'Email already used in this tenant.');
            }
            foreach ($this->input('group_ids', []) as $i => $groupId) {
                if ($groupId && ! ConfigGroup::query()->whereKey($groupId)->exists()) {
                    $validator->errors()->add("group_ids.$i", 'Invalid group.');
                }
            }
        });
    }
}
