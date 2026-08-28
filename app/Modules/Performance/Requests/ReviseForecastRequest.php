<?php

namespace App\Modules\Performance\Requests;

use App\Modules\Performance\Models\Period;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** §3H — revising a forecast never changes its subject/budget/kpi link, only the horizon, notes, and lines. */
class ReviseForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_id' => 'required|integer',
            'notes' => 'nullable|string|max:500',
            'lines' => 'nullable|array',
            'lines.*.period_id' => 'required_with:lines|integer',
            'lines.*.forecast_value' => 'required_with:lines|numeric',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $periodId = $this->input('period_id');
            if ($periodId && ! Period::query()->whereKey($periodId)->exists()) {
                $validator->errors()->add('period_id', 'The selected period is invalid.');
            }

            foreach ($this->input('lines', []) as $index => $line) {
                if (! empty($line['period_id']) && ! Period::query()->whereKey($line['period_id'])->exists()) {
                    $validator->errors()->add("lines.{$index}.period_id", 'The selected period is invalid.');
                }
            }
        });
    }
}
