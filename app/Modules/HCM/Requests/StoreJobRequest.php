<?php

namespace App\Modules\HCM\Requests;

use App\Modules\HCM\Models\Job;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $job = $this->route('job');

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique(Job::class, 'code')->ignore($job)],
            'title' => ['required', 'string', 'max:150'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
