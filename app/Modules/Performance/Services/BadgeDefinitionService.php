<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\BadgeDefinition;

/** §3I — tenant-editable badge/rule CRUD (same shape as PerspectiveService/OkrCycleService). */
class BadgeDefinitionService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): BadgeDefinition
    {
        return BadgeDefinition::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(BadgeDefinition $badge, array $data): BadgeDefinition
    {
        $badge->update($this->attributes($data));

        return $badge->refresh();
    }

    public function delete(BadgeDefinition $badge): void
    {
        $badge->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'trigger_type' => $data['trigger_type'],
            'trigger_params' => $data['trigger_params'] ?? null,
            'icon' => $data['icon'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];
    }
}
