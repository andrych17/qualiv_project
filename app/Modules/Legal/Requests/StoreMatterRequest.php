<?php

namespace App\Modules\Legal\Requests;

use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\Matter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMatterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'nullable|string|max:50|alpha_dash',
            'title' => 'required|string|max:255',
            'matter_type' => 'nullable|string|max:100',
            'partner_id' => 'nullable|integer',
            'converted_from_lead_id' => 'nullable|integer',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'status' => ['required', Rule::in(Matter::STATUSES)],
            'opened_at' => 'nullable|date',
            'target_close_at' => 'nullable|date|after_or_equal:opened_at',
            'notes' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    /**
     * Plain `exists:` rules can't reach schema-qualified tables (Laravel's rule
     * parser reads the dot in "CRM.partners" as a connection name, not a schema) —
     * so CRM.* references are checked here instead, same as StoreContactRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $code = $this->input('code');
            if ($code && Matter::query()->where('code', $code)->exists()) {
                $validator->errors()->add('code', 'Matter code already exists.');
            }

            $partnerId = $this->input('partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('partner_id', 'The selected client is invalid.');
            }

            $leadId = $this->input('converted_from_lead_id');
            if ($leadId && ! Lead::query()->whereKey($leadId)->where('stage', Lead::STAGE_CONVERTED)->exists()) {
                $validator->errors()->add('converted_from_lead_id', 'The selected lead is invalid or not yet converted.');
            }
        });
    }
}
