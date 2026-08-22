<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\ResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resource_type_id' => 'required|integer',
            'name' => 'required|string|max:150',
            'location_notes' => 'nullable|string',
            'capacity' => 'nullable|integer|min:0',
            'working_hours' => 'nullable|array',
            'working_hours.*.day_of_week' => 'required|integer|between:0,6',
            'working_hours.*.start_time' => 'required|date_format:H:i',
            'working_hours.*.end_time' => 'required|date_format:H:i',
        ];
    }

    /**
     * exists:SCHEDULE.resource_types,id can't be used — see CRM's
     * StoreContactRequest::withValidator(). Also checks start < end per row
     * here rather than via a wildcard `after:` rule, which is fragile across
     * sibling array fields.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $typeId = $this->input('resource_type_id');
            if ($typeId && ! ResourceType::query()->whereKey($typeId)->exists()) {
                $validator->errors()->add('resource_type_id', 'The selected resource type is invalid.');
            }

            foreach ((array) $this->input('working_hours', []) as $i => $row) {
                if (($row['start_time'] ?? null) && ($row['end_time'] ?? null) && $row['start_time'] >= $row['end_time']) {
                    $validator->errors()->add("working_hours.{$i}.end_time", 'End time must be after start time.');
                }
            }
        });
    }
}
