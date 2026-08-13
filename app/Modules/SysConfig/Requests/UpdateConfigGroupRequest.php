<?php

namespace App\Modules\Config\Requests;

use App\Models\User;
use App\Modules\Config\Models\ConfigGroup;
use App\Modules\Config\Models\ConfigMenu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateConfigGroupRequest extends FormRequest
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
            'rights' => 'nullable|array',
            'rights.*.menu_id' => 'required|integer',
            'rights.*.create' => 'boolean',
            'rights.*.read' => 'boolean',
            'rights.*.update' => 'boolean',
            'rights.*.delete' => 'boolean',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var ConfigGroup|null $group */
            $group = $this->route('group');
            $groupId = $group?->id;

            $code = $this->input('code');
            if ($code && ConfigGroup::query()
                ->where('app_code', 'NUSAEVO')
                ->where('code', $code)
                ->when($groupId, fn ($q) => $q->where('id', '!=', $groupId))
                ->exists()) {
                $validator->errors()->add('code', 'The code has already been taken.');
            }

            foreach ($this->input('rights', []) as $i => $row) {
                $menuId = $row['menu_id'] ?? null;
                if ($menuId && ! ConfigMenu::query()->whereKey($menuId)->exists()) {
                    $validator->errors()->add("rights.$i.menu_id", 'Invalid menu.');
                }
            }

            foreach ($this->input('user_ids', []) as $i => $userId) {
                if ($userId && ! User::query()->whereKey($userId)->exists()) {
                    $validator->errors()->add("user_ids.$i", 'Invalid user.');
                }
            }
        });
    }
}
