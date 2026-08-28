<?php

namespace App\Modules\Performance\Services;

use App\Modules\Performance\Models\KpiDefinition;
use App\Modules\Performance\Models\Perspective;
use Illuminate\Validation\ValidationException;

class PerspectiveService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Perspective
    {
        return Perspective::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Perspective $perspective, array $data): Perspective
    {
        $perspective->update($this->attributes($data));

        return $perspective->refresh();
    }

    public function delete(Perspective $perspective): void
    {
        if (KpiDefinition::query()->where('perspective_id', $perspective->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'This perspective is used by an existing KPI — deactivate it instead.']);
        }

        $perspective->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
