<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Target;
use Illuminate\Validation\ValidationException;

class KpiDefinitionService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): KpiDefinition
    {
        return KpiDefinition::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(KpiDefinition $kpi, array $data): KpiDefinition
    {
        $kpi->update($this->attributes($data));

        return $kpi->refresh();
    }

    /** §3C: "A KPI must be active to accept new target assignments, but historical targets/values on a deactivated KPI remain visible" — deactivate, never delete a KPI with history. */
    public function delete(KpiDefinition $kpi): void
    {
        if (Target::query()->where('kpi_id', $kpi->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'This KPI has targets assigned against it — deactivate it instead.']);
        }

        $kpi->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'unit' => $data['unit'],
            'direction' => $data['direction'],
            'perspective_id' => $data['perspective_id'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
