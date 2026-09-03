<?php

namespace App\Modules\PP\Requests;

use App\Modules\PP\Models\Resource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                Resource::TYPE_TOOL, Resource::TYPE_TANK, Resource::TYPE_UTILITY, Resource::TYPE_WAREHOUSE,
            ])],
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:150',
            'capacity' => 'nullable|numeric|min:0',
            'uom_code' => 'nullable|string|max:10',
            'external_type' => 'nullable|string|max:20',
            'external_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ];
    }

    /** Schema-qualified tables (PP.*) can't be checked via `exists:`/`unique:` — see Inventory's StoreProductRequest. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (Resource::query()->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'This code is already in use.');
            }
        });
    }
}
