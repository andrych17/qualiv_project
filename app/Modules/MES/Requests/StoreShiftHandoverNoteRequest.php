<?php

namespace App\Modules\MES\Requests;

use App\Modules\HCM\Models\ShiftAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** MES_SPECS.md §3P — a handover note against an existing HCM shift assignment. */
class StoreShiftHandoverNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_assignment_id' => 'required|integer',
            'notes' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $shiftAssignmentId = $this->input('shift_assignment_id');
            if ($shiftAssignmentId && ! ShiftAssignment::query()->whereKey($shiftAssignmentId)->exists()) {
                $validator->errors()->add('shift_assignment_id', 'The selected shift assignment is invalid.');
            }
        });
    }
}
