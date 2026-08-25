<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_warehouse_id' => 'required|integer',
            'source_location_id' => 'required|integer',
            'destination_warehouse_id' => 'required|integer',
            'destination_location_id' => 'required|integer',
            'transfer_date' => 'required|date',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer',
            'lines.*.qty' => 'required|numeric|min:0.0001',
            'lines.*.uom_id' => 'required|integer',
            'lines.*.batch_id' => 'nullable|integer',
            'lines.*.serial_numbers' => 'nullable|array',
            'lines.*.serial_numbers.*' => 'nullable|string|max:80',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sourceLocationId = $this->input('source_location_id');
            $destinationLocationId = $this->input('destination_location_id');

            if ($sourceLocationId && ! Location::query()->whereKey($sourceLocationId)->exists()) {
                $validator->errors()->add('source_location_id', 'The selected source location is invalid.');
            }
            if ($destinationLocationId && ! Location::query()->whereKey($destinationLocationId)->exists()) {
                $validator->errors()->add('destination_location_id', 'The selected destination location is invalid.');
            }
            if ($sourceLocationId && $destinationLocationId && $sourceLocationId === $destinationLocationId) {
                $validator->errors()->add('destination_location_id', 'Source and destination location can\'t be the same.');
            }

            foreach ((array) $this->input('lines', []) as $i => $line) {
                $productId = $line['product_id'] ?? null;
                if ($productId && ! Product::query()->whereKey($productId)->exists()) {
                    $validator->errors()->add("lines.{$i}.product_id", 'The selected product is invalid.');
                }

                $uomId = $line['uom_id'] ?? null;
                if ($uomId && ! Uom::query()->whereKey($uomId)->exists()) {
                    $validator->errors()->add("lines.{$i}.uom_id", 'The selected UoM is invalid.');
                }
            }
        });
    }
}
