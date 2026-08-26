<?php

namespace App\Modules\Sales\Requests;

use App\Modules\Sales\Models\Territory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSalesTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'territory_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
            'member_user_ids' => ['nullable', 'array'],
            'member_user_ids.*' => ['exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $territoryId = $this->input('territory_id');
            if ($territoryId && ! Territory::query()->whereKey($territoryId)->exists()) {
                $validator->errors()->add('territory_id', 'The selected territory is invalid.');
            }
        });
    }
}
