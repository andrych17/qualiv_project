<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
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
            'legal_name' => ['required', 'string', 'max:255'],
            'npwp' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'base_currency' => ['required', 'string', 'size:3'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
            'ar_control_account_id' => ['nullable', 'integer'],
            'ap_control_account_id' => ['nullable', 'integer'],
            'inventory_control_account_id' => ['nullable', 'integer'],
            'payroll_net_pay_payable_account_id' => ['nullable', 'integer'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    // exists:ACCOUNTING.accounts,id can't be used — Laravel's exists rule parses the
    // dot as connection.table, not schema.table (see DMS\Requests\StoreFolderRequest).
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['ar_control_account_id', 'ap_control_account_id', 'inventory_control_account_id', 'payroll_net_pay_payable_account_id'] as $field) {
                $accountId = $this->input($field);
                if ($accountId && ! Account::query()->whereKey($accountId)->exists()) {
                    $validator->errors()->add($field, 'The selected account is invalid.');
                }
            }
        });
    }
}
