<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\OkrCycle;
use App\Modules\Performance\Models\OkrObjective;
use Illuminate\Validation\ValidationException;

class OkrCycleService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): OkrCycle
    {
        return OkrCycle::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(OkrCycle $cycle, array $data): OkrCycle
    {
        $cycle->update($this->attributes($data));

        return $cycle->refresh();
    }

    public function delete(OkrCycle $cycle): void
    {
        if (OkrObjective::query()->where('cycle_id', $cycle->id)->exists()) {
            throw ValidationException::withMessages(['label' => 'This cycle has Objectives against it — deactivate it instead.']);
        }

        $cycle->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'label' => $data['label'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
