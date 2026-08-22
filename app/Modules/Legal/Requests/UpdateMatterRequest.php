<?php

namespace App\Modules\Legal\Requests;

use App\Modules\CRM\Models\Partner;
use App\Modules\Legal\Models\Matter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMatterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:50|alpha_dash',
            'title' => 'required|string|max:255',
            'matter_type' => 'nullable|string|max:100',
            'partner_id' => 'nullable|integer',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'status' => ['required', Rule::in(Matter::STATUSES)],
            'opened_at' => 'nullable|date',
            'target_close_at' => 'nullable|date|after_or_equal:opened_at',
            'notes' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    /** exists:CRM.partners,id can't be used — see StoreMatterRequest::withValidator(). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Matter|null $matter */
            $matter = $this->route('matter');
            if (Matter::query()
                ->where('code', $this->input('code'))
                ->when($matter, fn ($q) => $q->where('id', '!=', $matter->id))
                ->exists()) {
                $validator->errors()->add('code', 'Matter code already exists.');
            }

            $partnerId = $this->input('partner_id');
            if ($partnerId && ! Partner::query()->whereKey($partnerId)->exists()) {
                $validator->errors()->add('partner_id', 'The selected client is invalid.');
            }
        });
    }
}
