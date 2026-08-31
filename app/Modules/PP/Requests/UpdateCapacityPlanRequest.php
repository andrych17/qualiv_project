<?php

namespace App\Modules\PP\Requests;

use App\Modules\PP\Models\CapacityPlan;
use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCapacityPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_group_id' => 'nullable|integer',
            'resource_type' => ['nullable', Rule::in([
                CapacityPlan::RESOURCE_TYPE_MES_WORK_CENTER,
                CapacityPlan::RESOURCE_TYPE_MES_MACHINE,
                CapacityPlan::RESOURCE_TYPE_PP_RESOURCE,
            ])],
            'resource_ref_id' => 'nullable|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'required_hours' => 'required|numeric|min:0',
            'available_hours' => 'required|numeric|min:0',
        ];
    }

    /** Schema-qualified tables (PP.*) can't be checked via `exists:`/`unique:` — see Inventory's StoreProductRequest. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $groupId = $this->input('resource_group_id');
            $resourceType = $this->input('resource_type');
            $resourceRefId = $this->input('resource_ref_id');

            $hasGroup = ! empty($groupId);
            $hasResource = ! empty($resourceType) && ! empty($resourceRefId);

            if ($hasGroup === $hasResource) {
                $validator->errors()->add('resource_group_id', 'Choose either a resource group or a single resource, not both or neither.');

                return;
            }

            if ($hasGroup && ! ResourceGroup::query()->whereKey($groupId)->exists()) {
                $validator->errors()->add('resource_group_id', 'The selected resource group is invalid.');
            }

            if ($hasResource && $resourceType === CapacityPlan::RESOURCE_TYPE_PP_RESOURCE
                && ! Resource::query()->whereKey($resourceRefId)->exists()) {
                $validator->errors()->add('resource_ref_id', 'The selected resource is invalid.');
            }
        });
    }
}
