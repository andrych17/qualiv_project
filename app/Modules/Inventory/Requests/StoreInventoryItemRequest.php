<?php
// ponytail: Reusable form request for validation rules
namespace App\Modules\Inventory\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|unique:inventory_items,code',
            'name' => 'required|string|max:255',
            'inventory_category_id' => 'required|exists:inventory_categories,id',
            'description' => 'nullable|string',
            'stock' => 'required|integer|min:0',
            'minimum_stock' => 'required|integer|min:0',
            'unit' => 'required|string|max:30',
            'status' => 'required|in:active,inactive,archived',
        ];
    }
}
