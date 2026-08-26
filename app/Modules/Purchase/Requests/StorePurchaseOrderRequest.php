<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_no' => ['nullable', 'string', 'max:30'],
            'supplier_id' => ['required', 'integer'],
            'pr_id' => ['nullable', 'integer'],
            'rfx_id' => ['nullable', 'integer'],
            'ship_to' => ['nullable', 'string', 'max:255'],
            'bill_to' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'incoterms' => ['nullable', 'string', 'max:20'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0'],
            'expected_delivery_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.expected_delivery_date' => ['nullable', 'date'],
            'lines.*.category_id' => ['nullable', 'integer'],
            'lines.*.local_content_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $supplierId = $this->input('supplier_id');
            if ($supplierId && ! Partner::query()->whereKey($supplierId)->exists()) {
                $validator->errors()->add('supplier_id', 'The selected supplier is invalid.');
            }

            $prId = $this->input('pr_id');
            if ($prId && ! PurRequisitionHdr::query()->whereKey($prId)->exists()) {
                $validator->errors()->add('pr_id', 'The selected requisition is invalid.');
            }

            foreach ((array) $this->input('lines', []) as $index => $line) {
                if (! empty($line['catalog_item_id']) && ! PurCatalogItem::query()->whereKey($line['catalog_item_id'])->exists()) {
                    $validator->errors()->add("lines.{$index}.catalog_item_id", 'The selected catalog item is invalid.');
                }
                if (! empty($line['category_id']) && ! Category::query()->whereKey($line['category_id'])->exists()) {
                    $validator->errors()->add("lines.{$index}.category_id", 'The selected category is invalid.');
                }
            }
        });
    }
}
