<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\WorkCenter;

/** MES_SPECS.md §3D — flat CRUD for a `mes_work_centers` row; no other table to keep in sync. */
class WorkCenterService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): WorkCenter
    {
        return WorkCenter::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(WorkCenter $workCenter, array $data): WorkCenter
    {
        $workCenter->update($this->attributes($data));

        return $workCenter->refresh();
    }

    public function delete(WorkCenter $workCenter): void
    {
        $workCenter->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'area_line' => $data['area_line'] ?? null,
            'type' => $data['type'],
        ];
    }
}
