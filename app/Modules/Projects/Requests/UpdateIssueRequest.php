<?php

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'assignee_id' => $this->input('assignee_id') === '' ? null : $this->input('assignee_id'),
            'due_date' => $this->input('due_date') === '' ? null : $this->input('due_date'),
        ]);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:task,bug,story',
            'status' => 'required|in:todo,in_progress,done',
            'priority' => 'required|in:low,medium,high,urgent',
            'assignee_id' => 'nullable|integer|exists:users,id',
            'due_date' => 'nullable|date',
        ];
    }
}
