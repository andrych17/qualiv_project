<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Company;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3H — "exactly one of item/category" is a service-level rule (InventoryGlMappingService::assertExactlyOneScope), not here, since it's a genuine cross-field business rule, same split AllocationRuleService uses for its own targets. */
class StoreInventoryGlMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer'],
            'inventory_item_id' => ['nullable', 'integer'],
            'inventory_category_id' => ['nullable', 'integer'],
            'inventory_asset_account_id' => ['required', 'integer'],
            'cogs_account_id' => ['nullable', 'integer'],
            'grni_account_id' => ['nullable', 'integer'],
            'adjustment_account_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $companyId = $this->input('company_id');
            if ($companyId && ! Company::query()->whereKey($companyId)->exists()) {
                $validator->errors()->add('company_id', 'The selected company is invalid.');
            }

            foreach (['inventory_asset_account_id', 'cogs_account_id', 'grni_account_id', 'adjustment_account_id'] as $field) {
                $accountId = $this->input($field);
                if ($accountId && ! Account::query()->whereKey($accountId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add($field, 'The selected account is invalid for this company.');
                }
            }

            $itemId = $this->input('inventory_item_id');
            if ($itemId && ! Product::query()->whereKey($itemId)->exists()) {
                $validator->errors()->add('inventory_item_id', 'The selected item is invalid.');
            }

            $categoryId = $this->input('inventory_category_id');
            if ($categoryId && ! ProductCategory::query()->whereKey($categoryId)->exists()) {
                $validator->errors()->add('inventory_category_id', 'The selected category is invalid.');
            }
        });
    }
}
