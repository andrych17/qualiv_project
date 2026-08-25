<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\AdjustmentReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAdjustmentReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:100',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (AdjustmentReason::query()->where('code', $this->input('code'))->exists()) {
                $validator->errors()->add('code', 'This code is already in use.');
            }
        });
    }
}
