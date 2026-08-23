<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_code' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:150'],
            'account_type' => ['required', 'string', 'in:'.implode(',', Account::TYPES)],
            'normal_balance' => ['required', 'string', 'in:'.implode(',', Account::BALANCES)],
            'parent_account_id' => ['nullable', 'integer'],
            'is_control_account' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Account $account */
            $account = $this->route('account');

            $parentId = $this->input('parent_account_id');
            if ($parentId && ! Account::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_account_id', 'The selected parent account is invalid.');
            }

            $dupe = Account::query()
                ->where('company_id', $account->company_id)
                ->where('account_code', $this->input('account_code'))
                ->whereKeyNot($account->id)
                ->exists();
            if ($dupe) {
                $validator->errors()->add('account_code', 'This account code is already used in the selected company.');
            }
        });
    }
}
