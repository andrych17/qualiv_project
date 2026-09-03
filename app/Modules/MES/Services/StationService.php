<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\Station;

/** MES_SPECS.md §3D — flat CRUD for a `mes_stations` row. */
class StationService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Station
    {
        return Station::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Station $station, array $data): Station
    {
        $station->update($this->attributes($data));

        return $station->refresh();
    }

    public function delete(Station $station): void
    {
        $station->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'work_center_id' => $data['work_center_id'] ?? null,
            'machine_id' => $data['machine_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
        ];
    }
}
