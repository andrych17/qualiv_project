<?php

namespace App\Modules\Schedule\Requests;

use App\Modules\Schedule\Models\SchedItem;
use App\Modules\Schedule\Requests\Concerns\ValidatesRecurrenceRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
{
    use ValidatesRecurrenceRule;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_at' => 'required|date',
            'priority' => 'nullable|string|in:'.implode(',', SchedItem::PRIORITIES),
            'owner_id' => 'nullable|integer|exists:users,id',
            'subject_type' => 'nullable|string|max:100|required_with:subject_id',
            'subject_id' => 'nullable|integer|required_with:subject_type',
            'recurrence_rule' => 'nullable|string|max:255',
            'watcher_ids' => 'nullable|array',
            'watcher_ids.*' => 'integer|exists:users,id',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->assertBoundedRecurrenceRule($v, $this->input('recurrence_rule')));
    }
}
