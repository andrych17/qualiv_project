<?php

namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** warehouse_id is fixed at creation — a shipment can't be moved to a different warehouse. */
class UpdateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'carrier' => 'nullable|string|max:60',
            'tracking_number' => 'nullable|string|max:80',
            'ship_date' => 'nullable|date',
            'pack_list_ids' => 'required|array|min:1',
            'pack_list_ids.*' => 'integer',
        ];
    }
}
