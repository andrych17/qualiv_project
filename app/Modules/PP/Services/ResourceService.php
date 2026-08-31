<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\Resource;

/** PP_SPECS.md §3E — flat CRUD for a `pp_resources` row; no custom fields (not in §4's registry) and no other table to keep in sync, so no transaction wrapper is needed. */
class ResourceService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Resource
    {
        return Resource::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Resource $resource, array $data): Resource
    {
        $resource->update($this->attributes($data));

        return $resource->refresh();
    }

    public function delete(Resource $resource): void
    {
        $resource->delete();
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'type' => $data['type'],
            'code' => $data['code'],
            'name' => $data['name'],
            'capacity' => $data['capacity'] ?? null,
            'uom_code' => $data['uom_code'] ?? null,
            'external_type' => $data['external_type'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
