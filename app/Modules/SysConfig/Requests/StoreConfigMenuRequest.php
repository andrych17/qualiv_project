<?php

namespace App\Modules\SysConfig\Requests;

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreConfigMenuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash',
            'menu_caption' => 'required|string|max:255',
            'menu_header' => 'nullable|string|max:100',
            'menu_link' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:100',
            'parent_id' => 'nullable|integer',
            'seq' => 'required|integer|min:0|max:9999',
            'status_code' => 'required|in:A,I',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // ponytail: Rule::unique/exists treat "SCHEMA.table" as connection.table — query model instead
        $validator->after(function (Validator $validator) {
            $code = $this->input('code');
            if ($code && ConfigMenu::query()->where('app_code', 'NUSAEVO')->where('code', $code)->exists()) {
                $validator->errors()->add('code', 'The code has already been taken.');
            }

            $parentId = $this->input('parent_id');
            if ($parentId && ! ConfigMenu::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_id', 'The selected parent menu is invalid.');
            }
        });
    }
}
