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
            'inventory_category_id' => InventoryCategory::inRandomOrder()->first()->id,
            'code' => 'ITEM-'.$this->faker->unique()->numberBetween(100, 999),
            'name' => ucfirst($this->faker->words(2, true)),
            'description' => $this->faker->sentence(),
            'stock' => $this->faker->numberBetween(5, 300),
            'minimum_stock' => $this->faker->numberBetween(10, 50),
            'unit' => $this->faker->randomElement(['pcs', 'box', 'unit', 'rim', 'kg', 'liter']),
            'status' => $this->faker->randomElement(['active', 'inactive', 'archived']),
        ];
    }
}
