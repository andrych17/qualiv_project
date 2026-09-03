<?php

namespace App\Modules\PP\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\PP\Models\ResourceGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** PP_SPECS.md §3J — one Setup & Changeover Matrix row: from/to at either product or family granularity. */
class StoreChangeoverMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_product_id' => 'nullable|integer|required_without:from_family',
            'from_family' => 'nullable|string|max:100|required_without:from_product_id',
            'to_product_id' => 'nullable|integer|required_without:to_family',
            'to_family' => 'nullable|string|max:100|required_without:to_product_id',
            'resource_group_id' => 'nullable|integer',
            'changeover_minutes' => 'required|integer|min:0',
            'cleaning_minutes' => 'required|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    /** Schema-qualified tables (PP.*, INVENTORY.*) can't be checked via `exists:` — see StoreResourceGroupRequest. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['from_product_id', 'to_product_id'] as $field) {
                $id = $this->input($field);
                if ($id && ! Product::query()->whereKey($id)->exists()) {
                    $validator->errors()->add($field, 'The selected product is invalid.');
                }
            }

            $groupId = $this->input('resource_group_id');
            if ($groupId && ! ResourceGroup::query()->whereKey($groupId)->exists()) {
                $validator->errors()->add('resource_group_id', 'The selected resource group is invalid.');
            }
        });
    }
}
