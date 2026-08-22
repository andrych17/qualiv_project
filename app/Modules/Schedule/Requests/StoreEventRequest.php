<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\ConferenceProvider;
use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Requests\Concerns\ValidatesRecurrenceRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreEventRequest extends FormRequest
{
    use ValidatesRecurrenceRule;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'all_day' => 'nullable|boolean',
            'location' => 'nullable|string|max:255',
            'owner_id' => 'nullable|integer|exists:users,id',
            'subject_type' => 'nullable|string|max:100|required_with:subject_id',
            'subject_id' => 'nullable|integer|required_with:subject_type',
            'recurrence_rule' => 'nullable|string|max:255',
            'attendee_ids' => 'nullable|array',
            'attendee_ids.*' => 'integer|exists:users,id',
            'resource_ids' => 'nullable|array',
            'resource_ids.*' => 'integer',
            'conference_provider_code' => 'nullable|string|max:30',
            'conference_manual_url' => 'nullable|url|max:500|required_if:conference_provider_code,'.ConferenceProvider::CODE_MANUAL,
        ];
    }

    /** exists:SCHEDULE.resources,id / conference_providers,code can't be used — see CRM's StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $resourceIds = array_filter((array) $this->input('resource_ids', []));
            if ($resourceIds && Resource::query()->whereIn('id', $resourceIds)->count() !== count($resourceIds)) {
                $validator->errors()->add('resource_ids', 'One or more selected resources are invalid.');
            }

            $providerCode = $this->input('conference_provider_code');
            if ($providerCode && ! ConferenceProvider::query()->where('code', $providerCode)->where('is_active', true)->exists()) {
                $validator->errors()->add('conference_provider_code', 'The selected conference provider is invalid.');
            }

            $this->assertBoundedRecurrenceRule($validator, $this->input('recurrence_rule'));
        });
    }
}
