<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'title_position' => 'nullable|string|max:100',
            'parent_partner_id' => 'nullable|integer',
            'owner_id' => 'nullable|integer|exists:users,id',
            'tags' => 'nullable|string|max:500',
            'role_type_ids' => 'nullable|array',
            'role_type_ids.*' => 'integer',
            'addresses' => 'nullable|array',
            'addresses.*.type' => 'required_with:addresses.*.line1|in:billing,shipping,office,other',
            'addresses.*.line1' => 'nullable|string|max:255',
            'addresses.*.line2' => 'nullable|string|max:255',
            'addresses.*.city' => 'nullable|string|max:100',
            'addresses.*.state_province' => 'nullable|string|max:100',
            'addresses.*.postal_code' => 'nullable|string|max:20',
            'addresses.*.country' => 'nullable|string|max:100',
            'addresses.*.is_primary' => 'nullable|boolean',
            'contact_points' => 'nullable|array',
            'contact_points.*.type' => 'required_with:contact_points.*.value|in:email,phone,mobile,fax',
            'contact_points.*.value' => 'nullable|string|max:255',
            'contact_points.*.is_primary' => 'nullable|boolean',
            'contact_points.*.opt_out' => 'nullable|boolean',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Plain `exists:` rules can't reach schema-qualified tables (Laravel's rule
     * parser reads the dot in "CRM.partners" as a connection name, not a schema
     * — see Illuminate\Validation\Concerns\ValidatesAttributes::parseTable) —
     * so CRM.* references are checked here instead, same as StoreLegalCaseRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_partner_id');
            if ($parentId && ! Partner::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_partner_id', 'The selected company is invalid.');
            }

            $roleTypeIds = array_filter((array) $this->input('role_type_ids', []));
            if ($roleTypeIds && PartnerRoleType::query()->whereIn('id', $roleTypeIds)->count() !== count($roleTypeIds)) {
                $validator->errors()->add('role_type_ids', 'One or more selected roles are invalid.');
            }
        });
    }
}
