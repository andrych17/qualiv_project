<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** pick_list_id is fixed at creation — a package can't be moved to a different pick list. */
class UpdatePackListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
}
