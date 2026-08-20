<?php

namespace App\Modules\CRM\Requests;

use App\Modules\CRM\Models\LeadSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'source_id' => 'nullable|integer',
            'owner_id' => 'nullable|integer|exists:users,id',
            'estimated_value' => 'nullable|numeric|min:0',
            'next_action_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    /** exists:CRM.lead_sources,id can't be used — see StoreContactRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $sourceId = $this->input('source_id');
            if ($sourceId && ! LeadSource::query()->whereKey($sourceId)->exists()) {
                $validator->errors()->add('source_id', 'The selected source is invalid.');
            }
        });
    }
}
