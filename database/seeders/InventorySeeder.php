<?php

// ponytail: Clean inventory data seeder matching specs and generating 50+ records

namespace Database\Seeders;

use App\Modules\Inventory\Models\InventoryCategory;
use App\Modules\Inventory\Models\InventoryItem;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Raw Material', 'code' => 'RAW'],
            ['name' => 'Finished Goods', 'code' => 'FG'],
            ['name' => 'Sparepart', 'code' => 'SP'],
            ['name' => 'Office Supply', 'code' => 'OS'],
            ['name' => 'Packaging', 'code' => 'PKG'],
            ['name' => 'Asset', 'code' => 'AST'],
        ];

        $categoryMap = [];
        foreach ($categories as $cat) {
            $created = InventoryCategory::query()->updateOrCreate(
                ['code' => $cat['code']],
                ['name' => $cat['name']],
            );
            $categoryMap[$cat['code']] = $created->id;
        }

        $items = [
            [
                'code' => 'RAW-001',
                'name' => 'Steel Plate 2mm',
                'description' => 'Raw material for production',
                'category_code' => 'RAW',
                'stock' => 120,
                'minimum_stock' => 30,
                'unit' => 'pcs',
                'status' => 'active',
            ],
            [
                'code' => 'RAW-002',
                'name' => 'Aluminium Sheet',
                'description' => 'Aluminium sheet for assembly',
                'category_code' => 'RAW',
                'stock' => 75,
                'minimum_stock' => 20,
                'unit' => 'pcs',
                'status' => 'active',
            ],
            [
                'code' => 'FG-001',
                'name' => 'Finished Product A',
                'description' => 'Ready to sell product',
                'category_code' => 'FG',
                'stock' => 45,
                'minimum_stock' => 10,
                'unit' => 'box',
                'status' => 'active',
            ],
            [
                'code' => 'SP-001',
                'name' => 'Bearing 6202',
                'description' => 'Machine sparepart',
                'category_code' => 'SP',
                'stock' => 8,
                'minimum_stock' => 15,
                'unit' => 'pcs',
                'status' => 'active',
            ],
            [
                'code' => 'OS-001',
                'name' => 'A4 Paper',
                'description' => 'Office printing paper',
                'category_code' => 'OS',
                'stock' => 30,
                'minimum_stock' => 10,
                'unit' => 'rim',
                'status' => 'active',
            ],
            [
                'code' => 'PKG-001',
                'name' => 'Carton Box Medium',
                'description' => 'Packaging carton box',
                'category_code' => 'PKG',
                'stock' => 200,
                'minimum_stock' => 50,
                'unit' => 'pcs',
                'status' => 'active',
            ],
            [
                'code' => 'AST-001',
                'name' => 'Barcode Scanner',
                'description' => 'Warehouse scanner device',
                'category_code' => 'AST',
                'stock' => 5,
                'minimum_stock' => 2,
                'unit' => 'unit',
                'status' => 'active',
            ],
            [
                'code' => 'AST-002',
                'name' => 'Old Printer',
                'description' => 'Archived office asset',
                'category_code' => 'AST',
                'stock' => 1,
                'minimum_stock' => 0,
                'unit' => 'unit',
                'status' => 'archived',
            ],
        ];

        foreach ($items as $item) {
            $catCode = $item['category_code'];
            unset($item['category_code']);
            $item['inventory_category_id'] = $categoryMap[$catCode];
            InventoryItem::query()->updateOrCreate(
                ['code' => $item['code']],
                $item,
            );
        }

        // ponytail: only bulk-factory on first seed so re-runs stay idempotent
        if (InventoryItem::query()->count() <= count($items)) {
            InventoryItem::factory()->count(50)->create();
        }
    }
}
