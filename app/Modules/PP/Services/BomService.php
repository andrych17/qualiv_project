<?php

namespace App\Modules\PP\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\BomLine;
use Illuminate\Support\Facades\DB;

/** PP_SPECS.md §3D — discrete BOM header + lines CRUD. */
class BomService
{
    public const ENTITY = 'pp_bom';

    public function __construct(protected CustomFieldService $customFields) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Bom
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($data, $custom) {
            if ($data['is_active'] ?? true) {
                $this->deactivateOthers($data['product_id']);
            }

            $bom = Bom::query()->create([
                'product_id' => $data['product_id'],
                'version' => $data['version'] ?? $this->nextVersion($data['product_id']),
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $this->syncLines($bom, $data['lines'] ?? []);
            $this->customFields->sync(self::ENTITY, $bom->id, $custom);

            return $bom->load('lines');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Bom $bom, array $data): Bom
    {
        $custom = $this->customFields->validateAndNormalize(self::ENTITY, $data['custom_fields'] ?? []);

        return DB::transaction(function () use ($bom, $data, $custom) {
            if (($data['is_active'] ?? false) && ! $bom->is_active) {
                $this->deactivateOthers($bom->product_id);
            }

            $bom->update([
                'effective_from' => $data['effective_from'] ?? $bom->effective_from,
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $bom->is_active,
            ]);

            $this->syncLines($bom, $data['lines'] ?? []);
            $this->customFields->sync(self::ENTITY, $bom->id, $custom);

            return $bom->refresh()->load('lines');
        });
    }

    public function delete(Bom $bom): void
    {
        DB::transaction(function () use ($bom) {
            $this->customFields->deleteFor(self::ENTITY, $bom->id);
            $bom->delete();
        });
    }

    private function nextVersion(int $productId): int
    {
        return (int) Bom::query()->where('product_id', $productId)->max('version') + 1;
    }

    /** Only one `is_active` BOM per product (§3D rule) — deactivate before the new one is written, same DB partial-unique-index defense as the migration. */
    private function deactivateOthers(int $productId, ?int $exceptBomId = null): void
    {
        Bom::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->when($exceptBomId, fn ($query) => $query->where('id', '!=', $exceptBomId))
            ->update(['is_active' => false]);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function syncLines(Bom $bom, array $lines): void
    {
        $bom->lines()->delete();

        foreach ($lines as $line) {
            if (empty($line['component_product_id']) || ! isset($line['qty_per_parent_unit'])) {
                continue;
            }

            BomLine::query()->create([
                'bom_id' => $bom->id,
                'component_product_id' => $line['component_product_id'],
                'qty_per_parent_unit' => $line['qty_per_parent_unit'],
                'uom_code' => $line['uom_code'] ?? null,
                'scrap_pct' => $line['scrap_pct'] ?? 0,
            ]);
        }
    }
}
