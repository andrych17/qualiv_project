<?php

namespace App\Modules\Sales\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\SalesTeam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id' => ['required', 'integer'],
            'sales_team_id' => ['nullable', 'integer'],
            'price_list_id' => ['nullable', 'integer'],
            'assigned_rep_id' => ['nullable', 'exists:users,id'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'on_hold' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $partnerId = $this->input('partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('partner_id', 'The selected customer is invalid.');
            }

            $teamId = $this->input('sales_team_id');
            if ($teamId && ! SalesTeam::query()->whereKey($teamId)->exists()) {
                $validator->errors()->add('sales_team_id', 'The selected sales team is invalid.');
            }

            $priceListId = $this->input('price_list_id');
            if ($priceListId && ! PriceList::query()->whereKey($priceListId)->exists()) {
                $validator->errors()->add('price_list_id', 'The selected price list is invalid.');
            }
        });
    }
}
