<?php

namespace App\Modules\CustomFields\Services;

use App\Modules\CustomFields\Models\FieldDef;
use App\Modules\CustomFields\Models\FieldDefAuditLog;
use Illuminate\Support\Facades\Cache;

class FieldDefService
{
    public function create(array $data): FieldDef
    {
        $row = FieldDef::query()->create($this->attrs($data));
        $this->audit($row, 'created', null, $this->snapshot($row));
        $this->bumpCache($row->entity_type);

        return $row;
    }

    public function update(FieldDef $def, array $data): FieldDef
    {
        $before = $this->snapshot($def);
        $def->update($this->attrs($data, $def));
        $def->refresh();
        $this->audit($def, 'updated', $before, $this->snapshot($def));
        $this->bumpCache($def->entity_type);

        return $def;
    }

    public function delete(FieldDef $def): void
    {
        $before = $this->snapshot($def);
        $def->update(['status' => 'inactive']);
        $this->audit($def->refresh(), 'deactivated', $before, $this->snapshot($def));
        $this->bumpCache($def->entity_type);
    }

    public function bumpCache(string $entityType): void
    {
        $key = $this->versionKey($entityType);
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }
        Cache::increment($key);
    }

    public function cacheVersion(string $entityType): int
    {
        return (int) Cache::get($this->versionKey($entityType), 1);
    }

    /** @param  array<string, mixed>  $data */
    private function attrs(array $data, ?FieldDef $existing = null): array
    {
        $type = $data['field_type'] ?? $existing?->field_type ?? 'text';
        $options = $type === 'select' ? ($data['options'] ?? $existing?->options) : null;

        return [
            'entity_type' => $data['entity_type'],
            'module_code' => ($data['module_code'] ?? '') !== '' ? $data['module_code'] : null,
            'code' => $data['code'],
            'label' => $data['label'],
            'field_type' => $type,
            'options' => $options,
            'is_required' => (bool) ($data['is_required'] ?? false),
            'seq' => (int) ($data['seq'] ?? $existing?->seq ?? 0),
            'status' => $data['status'] ?? $existing?->status ?? 'active',
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(FieldDef $def): array
    {
        return $def->only(['entity_type', 'module_code', 'code', 'label', 'field_type', 'options', 'is_required', 'seq', 'status']);
    }

    /** @param  array<string, mixed>|null  $before */
    /** @param  array<string, mixed>|null  $after */
    private function audit(FieldDef $def, string $action, ?array $before, ?array $after): void
    {
        FieldDefAuditLog::query()->create([
            'field_def_id' => $def->id,
            'action' => $action,
            'actor_id' => auth()->id(),
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'created_at' => now(),
        ]);
    }

    private function versionKey(string $entityType): string
    {
        $tenant = tenancy()->initialized ? (string) tenant()->getTenantKey() : 'central';

        return "tenant:{$tenant}:customfields:ver:{$entityType}";
    }
}
