<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'parent_category_id' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_category_id');
            if ($parentId && ! ProductCategory::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_category_id', 'The selected parent category is invalid.');
            }
        });
    }
}
