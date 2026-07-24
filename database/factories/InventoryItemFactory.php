<?php

// ponytail: Simple model factory

namespace Database\Factories;

use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'inventory_category_id' => InventoryCategory::inRandomOrder()->first()?->id ?? InventoryCategory::factory(),
            'code' => 'ITEM-'.fake()->unique()->numberBetween(100, 999),
            'name' => ucfirst(fake()->words(2, true)),
            'description' => fake()->sentence(),
            'stock' => fake()->numberBetween(5, 300),
            'minimum_stock' => fake()->numberBetween(10, 50),
            'unit' => fake()->randomElement(['pcs', 'box', 'unit', 'rim', 'kg', 'liter']),
            'status' => fake()->randomElement(['active', 'inactive', 'archived']),
        ];
    }
}
