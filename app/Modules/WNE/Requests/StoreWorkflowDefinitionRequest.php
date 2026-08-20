<?php

namespace App\Modules\WNE\Requests;

use App\Modules\WNE\Models\WrkflowCategory;
use App\Modules\WNE\Models\WrkflowDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreWorkflowDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:100|regex:/^[a-z0-9_.]+$/', // what a calling module references (§3B) — kept to a safe identifier shape
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'category_id' => 'nullable|integer',
        ];
    }

    /** exists:WNE.wrkflow_definitions/categories can't be used — see CRM's StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('code') && WrkflowDefinition::query()->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'That code is already in use.');
            }

            $categoryId = $this->input('category_id');
            if ($categoryId && ! WrkflowCategory::query()->whereKey($categoryId)->exists()) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }
        });
    }
}
