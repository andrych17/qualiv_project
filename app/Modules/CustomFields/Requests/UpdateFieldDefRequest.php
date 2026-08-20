<?php

namespace App\Modules\CustomFields\Requests;

use App\Modules\CustomFields\Models\FieldDef;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateFieldDefRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_type' => 'required|string|max:50',
            'module_code' => 'nullable|string|max:50',
            'code' => 'required|string|max:50|alpha_dash',
            'label' => 'required|string|max:255',
            'field_type' => 'required|in:text,number,date,select',
            'options' => 'nullable|array',
            'options.*.label' => 'required_with:options|string|max:100',
            'options.*.value' => 'required_with:options|string|max:100',
            'is_required' => 'sometimes|boolean',
            'seq' => 'required|integer|min:0|max:9999',
            'status' => 'required|in:active,inactive',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('field_type') === 'select' && empty($this->input('options'))) {
                $validator->errors()->add('options', 'Select fields need at least one option.');
            }
            /** @var FieldDef|null $def */
            $def = $this->route('fieldDef');
            if (FieldDef::query()
                ->where('entity_type', $this->input('entity_type'))
                ->where('code', $this->input('code'))
                ->when($def, fn ($q) => $q->where('id', '!=', $def->id))
                ->exists()) {
                $validator->errors()->add('code', 'This field code already exists for the entity type.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_required' => $this->boolean('is_required'),
            'module_code' => $this->filled('module_code') ? strtoupper((string) $this->input('module_code')) : null,
        ]);
    }
}
