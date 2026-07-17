<?php

// ponytail: Clean business service layer processing DB transactions directly

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\InventoryItem;

class InventoryItemService
{
    public function createItem(array $data): InventoryItem
    {
        return InventoryItem::create($data);
    }

    public function updateItem(InventoryItem $item, array $data): InventoryItem
    {
        $item->update($data);

        return $item;
    }

    public function deleteItem(InventoryItem $item): bool
    {
        return $item->delete();
    }
}
