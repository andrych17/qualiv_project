<?php

namespace App\Modules\PP\Requests;

use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Models\ResourceGroupMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreResourceGroupRequest extends FormRequest
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
            if (ResourceGroup::query()->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'This code is already in use.');
            }

            // Only pp_resource refs are checkable — mes_* types point at MES tables that
            // don't exist yet (§3E), so those stay informational/app-trusted.
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
