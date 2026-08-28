<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => 'required|string|max:30',
            'period_type' => 'required|in:year,quarter,month',
            'year' => 'required|integer|min:2000|max:2100',
            'quarter' => 'nullable|integer|min:1|max:4',
            'month' => 'nullable|integer|min:1|max:12',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $period = $this->route('period');

            if (Period::query()->where('label', $this->input('label'))->where('id', '!=', $period?->id)->exists()) {
                $validator->errors()->add('label', 'This period label already exists.');
            }
        });
    }
}
