<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Perspective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreKpiDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'unit' => 'required|in:number,percent,currency,ratio',
            'direction' => 'required|in:higher_is_better,lower_is_better',
            'perspective_id' => 'nullable|integer',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (KpiDefinition::query()->where('name', $this->input('name'))->exists()) {
                $validator->errors()->add('name', 'This KPI already exists.');
            }

            $perspectiveId = $this->input('perspective_id');
            if ($perspectiveId && ! Perspective::query()->whereKey($perspectiveId)->exists()) {
                $validator->errors()->add('perspective_id', 'The selected perspective is invalid.');
            }
        });
    }
}
