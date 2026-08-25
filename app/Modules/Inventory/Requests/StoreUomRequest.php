<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Uom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:10',
            'name' => 'required|string|max:50',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (Uom::query()->where('code', strtoupper((string) $this->input('code')))->exists()) {
                $validator->errors()->add('code', 'This UoM code is already in use.');
            }
        });
    }
}
