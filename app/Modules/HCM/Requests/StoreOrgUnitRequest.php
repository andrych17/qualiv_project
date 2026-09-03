<?php

namespace App\Modules\HCM\Requests;

use App\Modules\HCM\Models\OrgUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOrgUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'unit_type' => ['required', 'string', Rule::in(OrgUnit::TYPES)],
            'parent_org_unit_id' => ['nullable', 'integer'],
            'accounting_cost_center_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_org_unit_id');
            if ($parentId && ! OrgUnit::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_org_unit_id', 'The selected parent unit is invalid.');
            }
        });
    }
}
