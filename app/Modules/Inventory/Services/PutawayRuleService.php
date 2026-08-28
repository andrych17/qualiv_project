<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PutawayRule;

/** §3R — tenant-editable lookup, plus the resolve() lookup Goods Receipt (§3D) calls at line-save time. */
class PutawayRuleService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): PutawayRule
    {
        return PutawayRule::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(PutawayRule $rule, array $data): PutawayRule
    {
        $rule->update($this->attributes($data));

        return $rule->refresh();
    }

    public function delete(PutawayRule $rule): void
    {
        $rule->delete();
    }

    /**
     * §3R "first-matching-rule wins" — rules are evaluated in ascending `priority_order`, so a
     * specific-product rule can be given a lower number than a broader category rule for the
     * same warehouse to take precedence over it. Returns null when nothing matches, leaving
     * the caller's line without a default (never a hard failure — put-away is a convenience,
     * not a requirement).
     */
    public function resolve(int $productId, int $warehouseId): ?int
    {
        $product = Product::query()->find($productId);
        if ($product === null) {
            return null;
        }

        $rules = PutawayRule::query()
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->orderBy('priority_order')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->product_id !== null && $rule->product_id === $productId) {
                return $rule->target_location_id;
            }
            if ($rule->category_id !== null && $rule->category_id === $product->category_id) {
                return $rule->target_location_id;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'warehouse_id' => $data['warehouse_id'],
            'product_id' => $data['product_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'target_location_id' => $data['target_location_id'],
            'priority_order' => $data['priority_order'] ?? 0,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
        ];
    }
}
