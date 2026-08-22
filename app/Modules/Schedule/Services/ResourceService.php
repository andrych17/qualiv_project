<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\SchedWorkingHour;
use Illuminate\Support\Facades\DB;

/** §3D — Resource Management: bookable rooms/equipment/vehicles/staff, plus optional weekly working hours. */
class ResourceService
{
    public function create(array $data): Resource
    {
        return DB::transaction(function () use ($data) {
            $resource = Resource::query()->create([
                'resource_type_id' => $data['resource_type_id'],
                'name' => $data['name'],
                'location_notes' => $data['location_notes'] ?? null,
                'capacity' => $data['capacity'] ?? null,
            ]);

            $this->syncWorkingHours($resource, $data['working_hours'] ?? []);

            return $resource;
        });
    }

    public function update(Resource $resource, array $data): Resource
    {
        return DB::transaction(function () use ($resource, $data) {
            $resource->update([
                'resource_type_id' => $data['resource_type_id'],
                'name' => $data['name'],
                'location_notes' => $data['location_notes'] ?? null,
                'capacity' => $data['capacity'] ?? null,
                'is_active' => $data['is_active'] ?? $resource->is_active,
            ]);

            $this->syncWorkingHours($resource, $data['working_hours'] ?? []);

            return $resource->refresh();
        });
    }

    /** Deactivate rather than delete — resources are FK-referenced by past bookings. */
    public function deactivate(Resource $resource): void
    {
        $resource->update(['is_active' => false]);
    }

    /** @param  list<array{day_of_week: int, start_time: string, end_time: string}>  $rows */
    private function syncWorkingHours(Resource $resource, array $rows): void
    {
        SchedWorkingHour::query()->where('resource_id', $resource->id)->delete();

        foreach ($rows as $row) {
            SchedWorkingHour::query()->create([
                'resource_id' => $resource->id,
                'day_of_week' => $row['day_of_week'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
            ]);
        }
    }
}
