<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLocationRequest extends FormRequest
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
            'is_active' => 'nullable|boolean',
            'barcodes' => 'nullable|array',
            'barcodes.*.barcode' => 'nullable|string|max:64',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $warehouse = $this->route('warehouse');
            /** @var Location $location */
            $location = $this->route('location');

            if (Location::query()->where('warehouse_id', $warehouse->id)->where('code', $this->input('code'))->where('id', '!=', $location->id)->exists()) {
                $validator->errors()->add('code', 'This code is already in use in this warehouse.');
            }

            $parentId = $this->input('parent_location_id');
            if (! $parentId) {
                return;
            }

            if ((int) $parentId === $location->id) {
                $validator->errors()->add('parent_location_id', 'A location cannot be its own parent.');

                return;
            }

            if (! Location::query()->where('id', $parentId)->where('warehouse_id', $warehouse->id)->exists()) {
                $validator->errors()->add('parent_location_id', 'The selected parent location is invalid.');

                return;
            }

            // Walk the proposed parent's ancestor chain — moving a location under one of its own
            // descendants would create a cycle that infinite-loops the tree rendering.
            $ancestorId = (int) $parentId;
            $depth = 0;
            while ($ancestorId && $depth < 100) {
                if ($ancestorId === $location->id) {
                    $validator->errors()->add('parent_location_id', 'Cannot move a location under its own descendant.');

                    return;
                }
                $ancestorId = Location::query()->whereKey($ancestorId)->value('parent_location_id');
                $depth++;
            }
        });
    }
}
