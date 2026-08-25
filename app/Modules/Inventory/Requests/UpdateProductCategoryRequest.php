<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProductCategoryRequest extends FormRequest
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
            $category = $this->route('category');
            $parentId = $this->input('parent_category_id');

            if ($category && (int) $parentId === $category->id) {
                $validator->errors()->add('parent_category_id', 'A category cannot be its own parent.');
            } elseif ($parentId && ! ProductCategory::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_category_id', 'The selected parent category is invalid.');
            }
        });
    }
}
