<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\CostCenter;
use App\Modules\Purchase\Models\PurCatalogItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pr_no' => ['nullable', 'string', 'max:30'],
            'requester_id' => ['nullable', 'integer', 'exists:users,id'],
            'cost_center_id' => ['nullable', 'integer'],
            'needed_by' => ['nullable', 'date'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.catalog_item_id' => ['nullable', 'integer'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.category_id' => ['nullable', 'integer'],
            'lines.*.local_content_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ccId = $this->input('cost_center_id');
            if ($ccId && ! CostCenter::query()->whereKey($ccId)->exists()) {
                $validator->errors()->add('cost_center_id', 'The selected cost center is invalid.');
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
