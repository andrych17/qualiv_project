<?php

namespace App\Modules\PP\Requests;

use App\Modules\Inventory\Models\Product;
use App\Modules\PP\Models\Bom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'is_active' => 'nullable|boolean',
            'lines' => 'required|array|min:1',
            'lines.*.component_product_id' => 'required|integer',
            'lines.*.qty_per_parent_unit' => 'required|numeric|min:0.000001',
            'lines.*.uom_code' => 'nullable|string|max:10',
            'lines.*.scrap_pct' => 'nullable|numeric|min:0|max:100',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Bom $bom */
            $bom = $this->route('bom');

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $componentId = $line['component_product_id'] ?? null;
                if ($componentId && ! Product::query()->whereKey($componentId)->exists()) {
                    $validator->errors()->add("lines.{$i}.component_product_id", 'The selected component is invalid.');
                }
                if ($componentId && $bom && (int) $componentId === (int) $bom->product_id) {
                    $validator->errors()->add("lines.{$i}.component_product_id", 'A BOM cannot use its own product as a component.');
                }
            }
        });
    }
}
