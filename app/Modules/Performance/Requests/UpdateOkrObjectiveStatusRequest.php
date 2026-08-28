<?php

namespace App\Modules\Performance\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** §3E — kanban drag-drop status change. */
class UpdateOkrObjectiveStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|in:on_track,at_risk,off_track,completed',
        ];
    }
}
