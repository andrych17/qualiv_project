<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\Period;
use App\Modules\Performance\Models\Target;
use Illuminate\Validation\ValidationException;

class PeriodService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Period
    {
        return Period::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Period $period, array $data): Period
    {
        $period->update($this->attributes($data));

        return $period->refresh();
    }

    public function delete(Period $period): void
    {
        if (Target::query()->where('period_id', $period->id)->exists()) {
            throw ValidationException::withMessages(['label' => 'This period has targets assigned against it — deactivate it instead.']);
        }

        $period->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'label' => $data['label'],
            'period_type' => $data['period_type'],
            'year' => $data['year'],
            'quarter' => $data['quarter'] ?? null,
            'month' => $data['month'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
