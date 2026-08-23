<?php

namespace App\Modules\Accounting\Requests;

use App\Modules\Accounting\Models\CostCenter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:150'],
            'parent_cost_center_id' => ['nullable', 'integer'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var CostCenter $costCenter */
            $costCenter = $this->route('costCenter');

            $parentId = $this->input('parent_cost_center_id');
            if ($parentId && (int) $parentId === $costCenter->id) {
                $validator->errors()->add('parent_cost_center_id', 'A cost center cannot be its own parent.');
            }
            if ($parentId && ! CostCenter::query()->whereKey($parentId)->exists()) {
                $validator->errors()->add('parent_cost_center_id', 'The selected parent cost center is invalid.');
            }

            $dupe = CostCenter::query()
                ->where('company_id', $costCenter->company_id)
                ->where('code', $this->input('code'))
                ->whereKeyNot($costCenter->id)
                ->exists();
            if ($dupe) {
                $validator->errors()->add('code', 'This code is already used in the selected company.');
            }
        });
    }
}
