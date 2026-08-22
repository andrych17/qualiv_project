<?php

namespace App\Modules\Schedule\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** §3F: shared by both Task and Event occurrence actions — same (date-only) payload shape either way. */
class SkipOccurrenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_occurrence_date' => 'required|date',
        ];
    }
}
