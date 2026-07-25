<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        $cat = InventoryCategory::inRandomOrder()->first();
        if (!$cat) {
            $cat = InventoryCategory::create(['name' => 'General', 'code' => 'GEN']);
        }

        return [
            'inventory_category_id' => $cat->id,
            'code' => 'ITEM-'.random_int(100, 99999),
            'name' => 'Sample Item '.random_int(1, 1000),
            'description' => 'Description for inventory item',
            'stock' => random_int(5, 300),
            'minimum_stock' => random_int(10, 50),
            'unit' => 'pcs',
            'status' => 'active',
        ];
    }
}
