<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3Q: a count is scoped exactly one of three ways — never zero, never more than one. */
class StoreCycleCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => 'required|integer',
            'location_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'abc_class' => 'nullable|in:A,B,C',
            'assigned_to' => 'nullable|integer',
            'scheduled_date' => 'nullable|date',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $scopes = array_filter([$this->input('location_id'), $this->input('category_id'), $this->input('abc_class')], fn ($v) => ! empty($v));
            if (count($scopes) !== 1) {
                $validator->errors()->add('location_id', 'Choose exactly one scope: a location, a category, or an ABC class.');
            }

            if ($this->input('warehouse_id') && ! Warehouse::query()->whereKey($this->input('warehouse_id'))->exists()) {
                $validator->errors()->add('warehouse_id', 'The selected warehouse is invalid.');
            }
            if ($this->input('location_id') && ! Location::query()->whereKey($this->input('location_id'))->exists()) {
                $validator->errors()->add('location_id', 'The selected location is invalid.');
            }
            if ($this->input('category_id') && ! ProductCategory::query()->whereKey($this->input('category_id'))->exists()) {
                $validator->errors()->add('category_id', 'The selected category is invalid.');
            }
        });
    }
}
