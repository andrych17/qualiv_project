<?php

namespace App\Modules\Schedule\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** §3F: shared by both Task (due-date-only, no end_at) and Event occurrence reschedules. */
class RescheduleOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_occurrence_date' => 'required|date',
            'start_at' => 'required|date',
            'end_at' => 'nullable|date|after_or_equal:start_at',
        ];
    }
}
