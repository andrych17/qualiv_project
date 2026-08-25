<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:30',
            'parent_location_id' => 'nullable|integer',
            'type' => 'nullable|in:zone,bin,staging,dock',
            'barcodes' => 'nullable|array',
            'barcodes.*.barcode' => 'nullable|string|max:64',
        ];
    }

    /** Schema-qualified tables (INVENTORY.*) can't be checked via `exists:`/`unique:` — see CRM's StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $warehouse = $this->route('warehouse');

            if (Location::query()->where('warehouse_id', $warehouse->id)->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'This code is already in use in this warehouse.');
            }

            $parentId = $this->input('parent_location_id');
            if ($parentId && ! Location::query()->where('id', $parentId)->where('warehouse_id', $warehouse->id)->exists()) {
                $validator->errors()->add('parent_location_id', 'The selected parent location is invalid.');
            }
        });
    }
}
