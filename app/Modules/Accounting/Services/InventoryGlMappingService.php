<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\AuditLog;
use App\Modules\Accounting\Models\InventoryGlMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * §3H — CRUD for the item/category → GL account mapping. Exactly one of
 * inventory_item_id/inventory_category_id is set per row (item-level overrides the
 * category-level default at posting time — see InventoryGlPostingService::resolveMapping()).
 *
 * create() upserts by scope rather than rejecting a duplicate: re-submitting the Create
 * screen for an item/category that already has a mapping is a plausible, harmless action
 * ("oh, this one's already mapped — let me just update it"), not a mistake worth blocking
 * on. update() is stricter — changing an existing row's scope onto one already claimed by a
 * DIFFERENT row is rejected, since silently merging two edited rows would lose one of them.
 */
class InventoryGlMappingService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data, int $userId): InventoryGlMapping
    {
        $this->assertExactlyOneScope($data);

        return DB::transaction(function () use ($data, $userId) {
            $existing = InventoryGlMapping::query()
                ->where('company_id', $data['company_id'])
                ->when(
                    $data['inventory_item_id'] ?? null,
                    fn ($q, $itemId) => $q->where('inventory_item_id', $itemId),
                    fn ($q) => $q->where('inventory_category_id', $data['inventory_category_id']),
                )
                ->first();

            if ($existing) {
                return $this->applyUpdate($existing, $data, $userId);
            }

            $mapping = InventoryGlMapping::query()->create([
                ...$data,
                'uuid' => (string) Str::uuid(),
                'created_by' => $userId,
            ]);

            AuditLog::record([
                'company_id' => $mapping->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.inventory_gl_mappings',
                'subject_id' => $mapping->id,
                'actor_id' => $userId,
                'after_snapshot' => $mapping->toArray(),
            ]);

            return $mapping->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(InventoryGlMapping $mapping, array $data, int $userId): InventoryGlMapping
    {
        $this->assertExactlyOneScope($data);

        $itemId = $data['inventory_item_id'] ?? null;
        $categoryId = $data['inventory_category_id'] ?? null;
        $collision = InventoryGlMapping::query()
            ->where('company_id', $mapping->company_id)
            ->where('id', '!=', $mapping->id)
            ->when($itemId, fn ($q) => $q->where('inventory_item_id', $itemId), fn ($q) => $q->where('inventory_category_id', $categoryId))
            ->exists();
        if ($collision) {
            throw ValidationException::withMessages(['inventory_item_id' => 'Another mapping already covers this item/category — edit that one instead.']);
        }

        return DB::transaction(fn () => $this->applyUpdate($mapping, $data, $userId));
    }

    public function delete(InventoryGlMapping $mapping, int $userId): void
    {
        DB::transaction(function () use ($mapping, $userId) {
            AuditLog::record([
                'company_id' => $mapping->company_id,
                'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
                'subject_type' => 'accounting.inventory_gl_mappings',
                'subject_id' => $mapping->id,
                'actor_id' => $userId,
                'before_snapshot' => $mapping->toArray(),
            ]);

            $mapping->delete();
        });
    }

    /** @param  array<string, mixed>  $data */
    private function applyUpdate(InventoryGlMapping $mapping, array $data, int $userId): InventoryGlMapping
    {
        $before = $mapping->toArray();
        $mapping->update($data);

        AuditLog::record([
            'company_id' => $mapping->company_id,
            'action' => AuditLog::ACTION_MASTER_DATA_CHANGED,
            'subject_type' => 'accounting.inventory_gl_mappings',
            'subject_id' => $mapping->id,
            'actor_id' => $userId,
            'before_snapshot' => $before,
            'after_snapshot' => $mapping->toArray(),
        ]);

        return $mapping->refresh();
    }

    /** @param  array<string, mixed>  $data */
    private function assertExactlyOneScope(array $data): void
    {
        $itemId = $data['inventory_item_id'] ?? null;
        $categoryId = $data['inventory_category_id'] ?? null;

        if (($itemId === null) === ($categoryId === null)) {
            throw ValidationException::withMessages(['inventory_item_id' => 'Set either an item or a category, not both and not neither.']);
        }
    }
}
