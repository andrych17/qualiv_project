<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FixedAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAssetDisposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disposal_date' => ['required', 'date'],
            'proceeds' => ['required', 'numeric', 'min:0'],
            'proceeds_gl_account_id' => ['nullable', 'integer'],
            'gain_loss_gl_account_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var FixedAsset $asset */
            $asset = $this->route('asset');
            $companyId = $asset->company_id;

            foreach (['proceeds_gl_account_id', 'gain_loss_gl_account_id'] as $field) {
                $accountId = $this->input($field);
                if ($accountId && ! Account::query()->whereKey($accountId)->where('company_id', $companyId)->exists()) {
                    $validator->errors()->add($field, 'The selected account is invalid for this company.');
                }
            }
        });
    }
}
