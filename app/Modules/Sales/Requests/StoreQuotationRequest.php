<?php

namespace App\Modules\Sales\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\PriceList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'opportunity_id' => ['nullable', 'integer'],
            'price_list_id' => ['nullable', 'integer'],
            'validity_date' => ['nullable', 'date'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_type' => ['required', 'in:product,service'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $customerId = $this->input('customer_id');
            if ($customerId && ! Partner::query()->whereKey($customerId)->exists()) {
                $validator->errors()->add('customer_id', 'The selected customer is invalid.');
            }

            $oppId = $this->input('opportunity_id');
            if ($oppId && ! Opportunity::query()->whereKey($oppId)->exists()) {
                $validator->errors()->add('opportunity_id', 'The selected opportunity is invalid.');
            }

            $priceListId = $this->input('price_list_id');
            if ($priceListId && ! PriceList::query()->whereKey($priceListId)->exists()) {
                $validator->errors()->add('price_list_id', 'The selected price list is invalid.');
            }
        });
    }
}
