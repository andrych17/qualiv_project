<?php

namespace App\Modules\Purchase\Requests;

use App\Modules\Purchase\Models\PurRfxInvitation;
use App\Modules\Purchase\Models\PurRfxLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordRfxResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitation_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'quotes' => ['required', 'array', 'min:1'],
            'quotes.*.rfx_line_id' => ['required', 'integer'],
            'quotes.*.price' => ['required', 'numeric', 'min:0'],
            'quotes.*.lead_time_days' => ['nullable', 'integer', 'min:0'],
            'quotes.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $invId = $this->input('invitation_id');
            if ($invId && ! PurRfxInvitation::query()->whereKey($invId)->exists()) {
                $validator->errors()->add('invitation_id', 'The selected invitation is invalid.');
            }

            foreach ((array) $this->input('quotes', []) as $index => $quote) {
                if (! empty($quote['rfx_line_id']) && ! PurRfxLine::query()->whereKey($quote['rfx_line_id'])->exists()) {
                    $validator->errors()->add("quotes.{$index}.rfx_line_id", 'The selected RFQ line is invalid.');
                }
            }
        });
    }
}
