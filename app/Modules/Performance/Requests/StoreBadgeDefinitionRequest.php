<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\BadgeDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBadgeDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'trigger_type' => ['required', Rule::in(BadgeDefinition::TRIGGER_TYPES)],
            'trigger_params.streak_length' => 'required_if:trigger_type,'.BadgeDefinition::TRIGGER_STREAK_ON_TRACK.'|integer|min:2',
            'icon' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ];
    }
}
