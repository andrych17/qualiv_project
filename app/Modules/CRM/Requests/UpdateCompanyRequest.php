<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\Industry;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'registration_tax_id' => 'nullable|string|max:50',
            'industry_id' => 'nullable|integer',
            'parent_partner_id' => 'nullable|integer',
            'owner_id' => 'nullable|integer|exists:users,id',
            'is_active' => 'nullable|boolean',
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

    /** See StoreContactRequest::withValidator() for why CRM.* isn't checked via `exists:`. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $partner = $this->route('company');

            $parentId = $this->input('parent_partner_id');
            if ($partner && (int) $parentId === $partner->id) {
                $validator->errors()->add('parent_partner_id', 'A partner cannot be its own parent.');
            } elseif ($parentId && ! Partner::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_partner_id', 'The selected parent company is invalid.');
            }

            $industryId = $this->input('industry_id');
            if ($industryId && ! Industry::query()->whereKey($industryId)->exists()) {
                $validator->errors()->add('industry_id', 'The selected industry is invalid.');
            }

            $roleTypeIds = array_filter((array) $this->input('role_type_ids', []));
            if ($roleTypeIds && PartnerRoleType::query()->whereIn('id', $roleTypeIds)->count() !== count($roleTypeIds)) {
                $validator->errors()->add('role_type_ids', 'One or more selected roles are invalid.');
            }
        });
    }
}
