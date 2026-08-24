<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AssetGroup;
use App\Modules\Accounting\Models\FixedAsset;
use App\Modules\CRM\Models\Partner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asset_group_id' => ['required', 'integer'],
            'asset_no' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'vendor_partner_id' => ['nullable', 'integer'],
            'acquisition_date' => ['required', 'date'],
            'acquisition_cost' => ['required', 'numeric', 'min:0.01'],
            'asset_gl_account_id' => ['required', 'integer'],
            'accumulated_depreciation_gl_account_id' => ['required', 'integer'],
            'depreciation_expense_gl_account_id' => ['required', 'integer'],
            'commercial_useful_life_months' => ['required', 'integer', 'min:1'],
            'commercial_method' => ['required', Rule::in(FixedAsset::METHODS)],
            'commercial_declining_rate' => ['nullable', 'numeric', 'min:0.0001', 'max:1'],
            'fiscal_method' => ['required', Rule::in(FixedAsset::METHODS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var FixedAsset $asset */
            $asset = $this->route('asset');
            $companyId = $asset->company_id;

            $groupId = $this->input('asset_group_id');
            if ($groupId && ! AssetGroup::query()->whereKey($groupId)->where('company_id', $companyId)->exists()) {
                $validator->errors()->add('asset_group_id', 'The selected asset group is invalid for this company.');
            }

            foreach (['asset_gl_account_id', 'accumulated_depreciation_gl_account_id', 'depreciation_expense_gl_account_id'] as $field) {
                $accountId = $this->input($field);
                if ($accountId && ! Account::query()->whereKey($accountId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add($field, 'The selected account is invalid for this company.');
                }
            }

            $partnerId = $this->input('vendor_partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('vendor_partner_id', 'The selected vendor is invalid.');
            }
        });
    }
}
