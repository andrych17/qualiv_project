<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurCatalogItem;
use Illuminate\Support\Collection;

class CatalogService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): PurCatalogItem
    {
        return PurCatalogItem::create([
            'item_code' => $data['item_code'],
            'description' => $data['description'],
            'category_id' => $data['category_id'] ?? null,
            'unit' => $data['unit'] ?? 'unit',
            'preferred_supplier_id' => $data['preferred_supplier_id'] ?? null,
            'negotiated_price' => $data['negotiated_price'] ?? null,
            'price_valid_from' => $data['price_valid_from'] ?? null,
            'price_valid_to' => $data['price_valid_to'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(PurCatalogItem $item, array $data): PurCatalogItem
    {
        $item->update([
            'item_code' => $data['item_code'] ?? $item->item_code,
            'description' => $data['description'] ?? $item->description,
            'category_id' => array_key_exists('category_id', $data) ? $data['category_id'] : $item->category_id,
            'unit' => $data['unit'] ?? $item->unit,
            'preferred_supplier_id' => array_key_exists('preferred_supplier_id', $data) ? $data['preferred_supplier_id'] : $item->preferred_supplier_id,
            'negotiated_price' => array_key_exists('negotiated_price', $data) ? $data['negotiated_price'] : $item->negotiated_price,
            'price_valid_from' => array_key_exists('price_valid_from', $data) ? $data['price_valid_from'] : $item->price_valid_from,
            'price_valid_to' => array_key_exists('price_valid_to', $data) ? $data['price_valid_to'] : $item->price_valid_to,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $item->is_active,
        ]);

        return $item->fresh(['category', 'preferredSupplier']);
    }

    public function toggleActive(PurCatalogItem $item): PurCatalogItem
    {
        $item->is_active = ! $item->is_active;
        $item->save();

        return $item;
    }

    /**
     * Search active catalog items with price validity check.
     */
    public function search(string $query = '', ?int $supplierId = null, ?int $categoryId = null): Collection
    {
        $q = PurCatalogItem::query()
            ->with(['category:id,name,kind', 'preferredSupplier:id,name'])
            ->where('is_active', true);

        if (! empty($query)) {
            $q->where(function ($sub) use ($query) {
                $sub->where('item_code', 'ilike', "%{$query}%")
                    ->orWhere('description', 'ilike', "%{$query}%");
            });
        }

        if ($supplierId) {
            $q->where('preferred_supplier_id', $supplierId);
        }

        if ($categoryId) {
            $q->where('category_id', $categoryId);
        }

        return $q->orderBy('item_code')->get();
    }
}
