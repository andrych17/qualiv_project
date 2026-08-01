<?php

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Narrow request for the Kanban board's quick-assign picker on each card. */
class UpdateIssueAssigneeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assignee_id' => 'nullable|integer|exists:users,id',
        ];
    }
}
