<?php

namespace App\Modules\WNE\Requests;

use App\Modules\WNE\Models\WrkflowCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Code is immutable after creation — it's what a calling module references (§3B) — so it's not accepted here. */
class UpdateWorkflowDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $categoryId = $this->input('category_id');
            if ($categoryId && ! WrkflowCategory::query()->whereKey($categoryId)->exists()) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }
        });
    }
}
