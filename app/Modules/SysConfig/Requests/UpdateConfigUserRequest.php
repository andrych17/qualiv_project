<?php

namespace App\Modules\SysConfig\Requests;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateConfigUserRequest extends FormRequest
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
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'group_ids' => 'nullable|array',
            'group_ids.*' => 'integer',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var User|null $user */
            $user = $this->route('user');
            $email = strtolower((string) $this->input('email'));
            if ($email && User::query()
                ->where('email', $email)
                ->when($user, fn ($q) => $q->where('id', '!=', $user->id))
                ->exists()) {
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
