<?php

namespace App\Modules\Sales\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\ContractSubscription;
use App\Modules\Sales\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:200'],
            'term_start' => ['required', 'date'],
            'term_end' => ['required', 'date', 'after:term_start'],
            'auto_renew' => ['boolean'],
            'price_list_id' => ['nullable', 'integer'],
            'subscriptions' => ['required', 'array', 'min:1'],
            'subscriptions.*.item_type' => ['required', 'in:product,service'],
            'subscriptions.*.product_id' => ['nullable', 'integer'],
            'subscriptions.*.description' => ['required', 'string', 'max:255'],
            'subscriptions.*.recurring_amount' => ['required', 'numeric', 'min:0'],
            'subscriptions.*.currency' => ['nullable', 'string', 'size:3'],
            'subscriptions.*.billing_interval' => ['required', 'in:'.implode(',', ContractSubscription::INTERVALS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $customerId = $this->input('customer_id');
            if ($customerId && ! Partner::query()->whereKey($customerId)->exists()) {
                $validator->errors()->add('customer_id', 'The selected customer is invalid.');
            }

            $priceListId = $this->input('price_list_id');
            if ($priceListId && ! PriceList::query()->whereKey($priceListId)->exists()) {
                $validator->errors()->add('price_list_id', 'The selected price list is invalid.');
            }
        });
    }
}
