<?php

namespace App\Modules\Sales\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'quote_id' => ['nullable', 'integer'],
            'price_list_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_type' => ['required', 'in:product,service'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'min:0.001'],
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

            $quoteId = $this->input('quote_id');
            if ($quoteId && ! Quotation::query()->whereKey($quoteId)->exists()) {
                $validator->errors()->add('quote_id', 'The selected quotation is invalid.');
            }

            $priceListId = $this->input('price_list_id');
            if ($priceListId && ! PriceList::query()->whereKey($priceListId)->exists()) {
                $validator->errors()->add('price_list_id', 'The selected price list is invalid.');
            }
        });
    }
}
