<?php

namespace App\Modules\Legal\Requests;

use App\Modules\Legal\Models\LegalCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLegalCaseRequest extends FormRequest
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
            'status' => 'required|in:open,pending,closed',
            'notes' => 'nullable|string',
            'custom_fields' => 'nullable|array',
            'custom_fields.*' => 'nullable|string|max:2000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var LegalCase|null $case */
            $case = $this->route('case');
            if (LegalCase::query()
                ->where('code', $this->input('code'))
                ->when($case, fn ($q) => $q->where('id', '!=', $case->id))
                ->exists()) {
                $validator->errors()->add('code', 'Case code already exists.');
            }
        });
    }
}
