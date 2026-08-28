<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\PickList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Structure/existence checks only — quantity-vs-remaining-picked and "line must be PICKED"
 * are post-time business rules enforced in PackListService (same split as Goods Issue's
 * over-issue checks living in the service, not here).
 */
class StorePackListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pick_list_id' => 'required|integer',
            'package_type' => 'nullable|in:carton,pallet',
            'weight' => 'nullable|numeric|min:0',
            'weight_uom' => 'nullable|string|max:10',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'dimension_uom' => 'nullable|string|max:10',
            'lines' => 'required|array|min:1',
            'lines.*.pick_list_line_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('pick_list_id') && ! PickList::query()->whereKey($this->input('pick_list_id'))->exists()) {
                $validator->errors()->add('pick_list_id', 'The selected pick list is invalid.');
            }
        });
    }
}
