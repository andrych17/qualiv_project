<?php

namespace App\Modules\PP\Requests;

use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Models\ResourceGroupMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateResourceGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:150',
            'is_active' => 'nullable|boolean',
            'members' => 'nullable|array',
            'members.*.resource_type' => ['required_with:members', Rule::in([
                ResourceGroupMember::TYPE_MES_WORK_CENTER,
                ResourceGroupMember::TYPE_MES_MACHINE,
                ResourceGroupMember::TYPE_MES_STATION,
                ResourceGroupMember::TYPE_PP_RESOURCE,
            ])],
            'members.*.resource_ref_id' => 'required_with:members|integer',
        ];
    }

    /** Schema-qualified tables (PP.*) can't be checked via `exists:`/`unique:` — see Inventory's StoreProductRequest. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $group = $this->route('resource_group');

            if (ResourceGroup::query()->where('code', $this->input('code'))->where('id', '!=', $group?->id)->exists()) {
                $validator->errors()->add('code', 'This code is already in use.');
            }

            foreach ((array) $this->input('members', []) as $i => $member) {
                if (($member['resource_type'] ?? null) === ResourceGroupMember::TYPE_PP_RESOURCE
                    && ! empty($member['resource_ref_id'])
                    && ! Resource::query()->whereKey($member['resource_ref_id'])->exists()) {
                    $validator->errors()->add("members.{$i}.resource_ref_id", 'The selected resource is invalid.');
                }
            }
        });
    }
}
