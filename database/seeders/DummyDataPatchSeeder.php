<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DummyDataPatchSeeder extends Seeder
{
    public function run(): void
    {
        if (function_exists('tenant') && tenant('id')) {
            $this->seedTenantData(tenant('id'));
            return;
        }

        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $t = Tenant::find('001');
            if ($t) {
                $tenants = collect([$t]);
            }
        }

        foreach ($tenants as $tenant) {
            $tenant->run(fn () => $this->seedTenantData($tenant->id));
        }
    }

    public function seedTenantData(string $tenantId): void
    {
        $this->seedInventory();
        $this->seedSalesPricing();
        $this->seedPosData();
        $this->seedAccounting();
        $this->seedCrm();
        $this->seedHcmAvatars();
        $this->seedProjects();
        $this->seedPerformance();
        $this->seedPp();
        $this->seedMes();
        $this->seedWne();
    }

    private function seedInventory(): void
    {
        // 1. UOMs
        $uoms = [
            ['code' => 'PCS', 'name' => 'Pieces / Buah'],
            ['code' => 'BOX', 'name' => 'Box / Kotak'],
            ['code' => 'CUP', 'name' => 'Cup / Cangkir'],
            ['code' => 'PORSI', 'name' => 'Porsi'],
            ['code' => 'BTL', 'name' => 'Botol'],
            ['code' => 'RIM', 'name' => 'Rim (500 Lembar)'],
            ['code' => 'KG', 'name' => 'Kilogram'],
            ['code' => 'L', 'name' => 'Liter'],
            ['code' => 'SET', 'name' => 'Set'],
            ['code' => 'UNIT', 'name' => 'Unit'],
        ];

        foreach ($uoms as $u) {
            DB::table('INVENTORY.uoms')->updateOrInsert(
                ['code' => $u['code']],
                ['name' => $u['name'], 'is_active' => true]
            );
        }

        $uomPcs = DB::table('INVENTORY.uoms')->where('code', 'PCS')->value('id');
        $uomCup = DB::table('INVENTORY.uoms')->where('code', 'CUP')->value('id');
        $uomPorsi = DB::table('INVENTORY.uoms')->where('code', 'PORSI')->value('id');
        $uomBtl = DB::table('INVENTORY.uoms')->where('code', 'BTL')->value('id');
        $uomRim = DB::table('INVENTORY.uoms')->where('code', 'RIM')->value('id');
        $uomUnit = DB::table('INVENTORY.uoms')->where('code', 'UNIT')->value('id');

        // 2. Product Categories
        $categories = [
            ['name' => 'Minuman & Kopi'],
            ['name' => 'Makanan & Snack'],
            ['name' => 'Alat Tulis & Kantor'],
            ['name' => 'Material & Logam'],
            ['name' => 'Perangkat Keras & Kasir'],
        ];

        foreach ($categories as $cat) {
            DB::table('INVENTORY.product_categories')->updateOrInsert(
                ['name' => $cat['name']],
                ['is_active' => true, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $catBeverage = DB::table('INVENTORY.product_categories')->where('name', 'Minuman & Kopi')->value('id');
        $catFood = DB::table('INVENTORY.product_categories')->where('name', 'Makanan & Snack')->value('id');
        $catStationery = DB::table('INVENTORY.product_categories')->where('name', 'Alat Tulis & Kantor')->value('id');
        $catMaterial = DB::table('INVENTORY.product_categories')->where('name', 'Material & Logam')->value('id');
        $catHardware = DB::table('INVENTORY.product_categories')->where('name', 'Perangkat Keras & Kasir')->value('id');

        // 3. Products
        $products = [
            [
                'sku' => 'ESP-001',
                'name' => 'Espresso Single Origin',
                'description' => 'Kopi espresso arabika premium dengan crema pekat dan aroma fruity.',
                'category_id' => $catBeverage,
                'base_uom_id' => $uomCup ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 20,
                'reorder_quantity' => 50,
                'image_url' => '/images/products/espresso.svg',
                'barcode' => '8991001001',
            ],
            [
                'sku' => 'CAP-001',
                'name' => 'Cappuccino Latte Art',
                'description' => 'Perpaduan espresso mantap dengan steamed milk lembut dan latte art.',
                'category_id' => $catBeverage,
                'base_uom_id' => $uomCup ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 20,
                'reorder_quantity' => 50,
                'image_url' => '/images/products/cappuccino.svg',
                'barcode' => '8991001002',
            ],
            [
                'sku' => 'TEA-001',
                'name' => 'Es Teh Manis Jasmine',
                'description' => 'Teh melati seduh segar dingin dengan rasa manis alami menyegarkan.',
                'category_id' => $catBeverage,
                'base_uom_id' => $uomCup ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 30,
                'reorder_quantity' => 100,
                'image_url' => '/images/products/iced-tea.svg',
                'barcode' => '8991001003',
            ],
            [
                'sku' => 'WAT-001',
                'name' => 'Air Mineral Pegunungan 600ml',
                'description' => 'Air mineral murni higienis dalam kemasan botol praktis.',
                'category_id' => $catBeverage,
                'base_uom_id' => $uomBtl ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 50,
                'reorder_quantity' => 120,
                'image_url' => '/images/products/mineral-water.svg',
                'barcode' => '8991001004',
            ],
            [
                'sku' => 'NAS-001',
                'name' => 'Nasi Goreng Spesial Telur',
                'description' => 'Nasi goreng bumbu gurih rempah lengkap dengan telur mata sapi dan acar.',
                'category_id' => $catFood,
                'base_uom_id' => $uomPorsi ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 15,
                'reorder_quantity' => 40,
                'image_url' => '/images/products/nasi-goreng.svg',
                'barcode' => '8991001005',
            ],
            [
                'sku' => 'MIE-001',
                'name' => 'Mie Goreng Jawa Spesial',
                'description' => 'Mie goreng kenyal dengan sayuran segar, irisan ayam, dan telur orak-arik.',
                'category_id' => $catFood,
                'base_uom_id' => $uomPorsi ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 15,
                'reorder_quantity' => 40,
                'image_url' => '/images/products/mie-goreng.svg',
                'barcode' => '8991001006',
            ],
            [
                'sku' => 'CRO-001',
                'name' => 'Butter Croissant French Bakery',
                'description' => 'Pastry croissant renyah di luar, lembut dan bermentega di dalam.',
                'category_id' => $catFood,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 10,
                'reorder_quantity' => 30,
                'image_url' => '/images/products/croissant.svg',
                'barcode' => '8991001007',
            ],
            [
                'sku' => 'SND-001',
                'name' => 'Club Sandwich Beef & Egg',
                'description' => 'Roti lapis panggang isi daging asap, keju cheddar, selada, dan telur.',
                'category_id' => $catFood,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 10,
                'reorder_quantity' => 25,
                'image_url' => '/images/products/sandwich.svg',
                'barcode' => '8991001008',
            ],
            [
                'sku' => 'SNP-001',
                'name' => 'Potato Chips Crispy 75g',
                'description' => 'Keripik kentang tipis gurih bumbu BBQ renyah dalam kemasan.',
                'category_id' => $catFood,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 25,
                'reorder_quantity' => 60,
                'image_url' => '/images/products/snack-chips.svg',
                'barcode' => '8991001009',
            ],
            [
                'sku' => 'PAP-001',
                'name' => 'Kertas HVS A4 80gsm PaperOne',
                'description' => 'Kertas cetak dan fotokopi putih bersih derajat keputihan tinggi.',
                'category_id' => $catStationery,
                'base_uom_id' => $uomRim ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 20,
                'reorder_quantity' => 50,
                'image_url' => '/images/products/kertas-a4.svg',
                'barcode' => '8991001010',
            ],
            [
                'sku' => 'STL-001',
                'name' => 'Pelat Baja Stainless 2mm x 1m',
                'description' => 'Lembaran pelat baja tahan karat grade 304 untuk fabrikasi industri.',
                'category_id' => $catMaterial,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 5,
                'reorder_quantity' => 15,
                'image_url' => '/images/products/steel-plate.svg',
                'barcode' => '8991001011',
            ],
            [
                'sku' => 'SCN-001',
                'name' => 'Handheld 2D Barcode Scanner USB',
                'description' => 'Pemindai kode batang laser 1D & QR Code 2D plug and play responsif.',
                'category_id' => $catHardware,
                'base_uom_id' => $uomUnit ?: $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 3,
                'reorder_quantity' => 10,
                'image_url' => '/images/products/barcode-scanner.svg',
                'barcode' => '8991001012',
            ],
        ];

        foreach ($products as $p) {
            $existing = DB::table('INVENTORY.products')->where('sku', $p['sku'])->first();
            $productId = null;
            if (!$existing) {
                $productId = DB::table('INVENTORY.products')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'sku' => $p['sku'],
                    'name' => $p['name'],
                    'description' => $p['description'],
                    'category_id' => $p['category_id'],
                    'base_uom_id' => $p['base_uom_id'],
                    'costing_method' => $p['costing_method'],
                    'reorder_point' => $p['reorder_point'],
                    'reorder_quantity' => $p['reorder_quantity'],
                    'tracking_mode' => 'none',
                    'is_active' => true,
                    'image_url' => $p['image_url'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $productId = $existing->id;
                DB::table('INVENTORY.products')->where('id', $productId)->update([
                    'name' => $p['name'],
                    'description' => $p['description'],
                    'category_id' => $p['category_id'],
                    'base_uom_id' => $p['base_uom_id'],
                    'image_url' => $p['image_url'],
                    'updated_at' => now(),
                ]);
            }

            // Product Barcode
            DB::table('INVENTORY.product_barcodes')->updateOrInsert(
                ['barcode' => $p['barcode']],
                [
                    'product_id' => $productId,
                    'type' => 'primary',
                    'unit_multiplier' => 1.0,
                ]
            );
        }

        // 4. Warehouse & Stock
        $warehouse = DB::table('INVENTORY.warehouses')->where('name', 'Toko Utama')->first();
        if (!$warehouse) {
            $warehouseId = DB::table('INVENTORY.warehouses')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => 'Toko Utama',
                'address' => 'Jl. Pemuda No. 123, Surabaya',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $warehouseId = $warehouse->id;
        }

        // Location
        $loc = DB::table('INVENTORY.locations')->where('warehouse_id', $warehouseId)->first();
        $locationId = null;
        if (!$loc) {
            $locationId = DB::table('INVENTORY.locations')->insertGetId([
                'warehouse_id' => $warehouseId,
                'code' => 'LOC-FRONT-01',
                'type' => 'bin',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $locationId = $loc->id;
        }

        // Seed stock balance across all warehouses
        $allWarehouses = DB::table('INVENTORY.warehouses')->get();
        $allProducts = DB::table('INVENTORY.products')->get();
        foreach ($allWarehouses as $wh) {
            $loc = DB::table('INVENTORY.locations')->where('warehouse_id', $wh->id)->first();
            $locId = $loc?->id;
            if (!$locId) {
                $locId = DB::table('INVENTORY.locations')->insertGetId([
                    'warehouse_id' => $wh->id,
                    'code' => 'LOC-' . $wh->id . '-FRONT',
                    'type' => 'bin',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ($allProducts as $prod) {
                DB::table('INVENTORY.stock_balances')->updateOrInsert(
                    [
                        'product_id' => $prod->id,
                        'warehouse_id' => $wh->id,
                    ],
                    [
                        'location_id' => $locId,
                        'qty_on_hand' => 100.0,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedSalesPricing(): void
    {
        $priceList = DB::table('SALES.price_lists')->where('is_tenant_default', true)->first();
        if (!$priceList) {
            $priceListId = DB::table('SALES.price_lists')->insertGetId([
                'name' => 'Standard Commercial Rates 2026',
                'currency' => 'IDR',
                'is_tenant_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $priceListId = $priceList->id;
        }

        $priceMap = [
            'ESP-001' => 22000,
            'CAP-001' => 28000,
            'TEA-001' => 10000,
            'WAT-001' => 8000,
            'NAS-001' => 35000,
            'MIE-001' => 32000,
            'CRO-001' => 25000,
            'SND-001' => 30000,
            'SNP-001' => 15000,
            'PAP-001' => 55000,
            'STL-001' => 450000,
            'SCN-001' => 650000,
        ];

        foreach ($priceMap as $sku => $price) {
            $prod = DB::table('INVENTORY.products')->where('sku', $sku)->first();
            if ($prod) {
                DB::table('SALES.price_list_lines')->updateOrInsert(
                    [
                        'price_list_id' => $priceListId,
                        'product_id' => $prod->id,
                    ],
                    [
                        'item_type' => 'product',
                        'description' => $prod->name,
                        'unit_price' => $price,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedPosData(): void
    {
        $priceList = DB::table('SALES.price_lists')->where('is_tenant_default', true)->first();
        $priceListId = $priceList?->id;

        $restaurantProfile = DB::table('POS.pos_profiles')->where('code', 'RESTAURANT')->first();
        $warehouse = DB::table('INVENTORY.warehouses')->first();

        // 1. Terminals
        $terminal = DB::table('POS.pos_terminals')->where('code', 'POS-01')->first();
        $terminalId = $terminal?->id;
        if (!$terminal) {
            $terminalId = DB::table('POS.pos_terminals')->insertGetId([
                'profile_id' => $restaurantProfile?->id ?? 1,
                'warehouse_id' => $warehouse?->id ?? 1,
                'code' => 'POS-01',
                'name' => 'Kasir Utama Depan',
                'receipt_prefix' => 'POS01',
                'default_price_list_id' => $priceListId,
                'last_local_seq' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('POS.pos_terminals')->where('id', $terminalId)->update([
                'default_price_list_id' => $priceListId,
                'profile_id' => $restaurantProfile?->id ?? $terminal->profile_id,
            ]);
        }

        // Secondary Terminal
        $terminal2 = DB::table('POS.pos_terminals')->where('code', 'POS-02')->first();
        if (!$terminal2) {
            DB::table('POS.pos_terminals')->insert([
                'profile_id' => $restaurantProfile?->id ?? 1,
                'warehouse_id' => $warehouse?->id ?? 1,
                'code' => 'POS-02',
                'name' => 'Kasir Bar & Minuman',
                'receipt_prefix' => 'POS02',
                'default_price_list_id' => $priceListId,
                'last_local_seq' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('POS.pos_terminals')->where('id', $terminal2->id)->update([
                'default_price_list_id' => $priceListId,
                'profile_id' => $restaurantProfile?->id ?? $terminal2->profile_id,
            ]);
        }

        // Update default_price_list_id for any other terminals
        if ($priceListId) {
            DB::table('POS.pos_terminals')->whereNull('default_price_list_id')->update([
                'default_price_list_id' => $priceListId,
            ]);
        }

        // 2. Floors & Tables
        $floor1 = DB::table('POS.pos_floors')->where('name', 'Lantai 1 (Main Dining)')->first();
        if (!$floor1) {
            $floor1Id = DB::table('POS.pos_floors')->insertGetId([
                'name' => 'Lantai 1 (Main Dining)',
                'layout_ref' => 'L1-MAIN',
            ]);
        } else {
            $floor1Id = $floor1->id;
        }

        $floor2 = DB::table('POS.pos_floors')->where('name', 'Lantai 2 (Outdoor Rooftop)')->first();
        if (!$floor2) {
            $floor2Id = DB::table('POS.pos_floors')->insertGetId([
                'name' => 'Lantai 2 (Outdoor Rooftop)',
                'layout_ref' => 'L2-ROOF',
            ]);
        } else {
            $floor2Id = $floor2->id;
        }

        // Tables for Floor 1
        $f1Tables = [
            ['code' => 'T-01', 'seat_count' => 2, 'pos_x' => 10, 'pos_y' => 10, 'status' => 'available'],
            ['code' => 'T-02', 'seat_count' => 2, 'pos_x' => 30, 'pos_y' => 10, 'status' => 'available'],
            ['code' => 'T-03', 'seat_count' => 4, 'pos_x' => 50, 'pos_y' => 10, 'status' => 'available'],
            ['code' => 'T-04', 'seat_count' => 4, 'pos_x' => 10, 'pos_y' => 40, 'status' => 'available'],
            ['code' => 'T-05', 'seat_count' => 6, 'pos_x' => 40, 'pos_y' => 40, 'status' => 'available'],
            ['code' => 'T-06', 'seat_count' => 6, 'pos_x' => 70, 'pos_y' => 40, 'status' => 'available'],
        ];
        foreach ($f1Tables as $t) {
            DB::table('POS.pos_tables')->updateOrInsert(
                ['floor_id' => $floor1Id, 'code' => $t['code']],
                ['seat_count' => $t['seat_count'], 'pos_x' => $t['pos_x'], 'pos_y' => $t['pos_y'], 'status' => $t['status']]
            );
        }

        // Tables for Floor 2
        $f2Tables = [
            ['code' => 'R-01', 'seat_count' => 4, 'pos_x' => 15, 'pos_y' => 15, 'status' => 'available'],
            ['code' => 'R-02', 'seat_count' => 4, 'pos_x' => 45, 'pos_y' => 15, 'status' => 'available'],
            ['code' => 'R-03', 'seat_count' => 8, 'pos_x' => 25, 'pos_y' => 50, 'status' => 'available'],
        ];
        foreach ($f2Tables as $t) {
            DB::table('POS.pos_tables')->updateOrInsert(
                ['floor_id' => $floor2Id, 'code' => $t['code']],
                ['seat_count' => $t['seat_count'], 'pos_x' => $t['pos_x'], 'pos_y' => $t['pos_y'], 'status' => $t['status']]
            );
        }

        // 3. KDS Stations
        $stations = [
            ['code' => 'BAR', 'name' => 'Bar & Coffee Station'],
            ['code' => 'KITCHEN', 'name' => 'Main Hot Kitchen'],
            ['code' => 'PASTRY', 'name' => 'Pastry & Dessert Station'],
        ];
        foreach ($stations as $st) {
            DB::table('POS.pos_kds_stations')->updateOrInsert(
                ['code' => $st['code']],
                ['name' => $st['name']]
            );
        }

        $kdsBarId = DB::table('POS.pos_kds_stations')->where('code', 'BAR')->value('id');
        $kdsKitchenId = DB::table('POS.pos_kds_stations')->where('code', 'KITCHEN')->value('id');
        $kdsPastryId = DB::table('POS.pos_kds_stations')->where('code', 'PASTRY')->value('id');

        // Product KDS Routing
        if ($kdsBarId) {
            $bevProds = DB::table('INVENTORY.products')->whereIn('sku', ['ESP-001', 'CAP-001', 'TEA-001', 'WAT-001'])->get();
            foreach ($bevProds as $bp) {
                DB::table('POS.pos_product_kds_routing')->updateOrInsert(
                    ['product_id' => $bp->id, 'kds_station_id' => $kdsBarId]
                );
            }
        }

        if ($kdsKitchenId) {
            $kitchenProds = DB::table('INVENTORY.products')->whereIn('sku', ['NAS-001', 'MIE-001', 'SND-001'])->get();
            foreach ($kitchenProds as $kp) {
                DB::table('POS.pos_product_kds_routing')->updateOrInsert(
                    ['product_id' => $kp->id, 'kds_station_id' => $kdsKitchenId]
                );
            }
        }

        if ($kdsPastryId) {
            $pastryProds = DB::table('INVENTORY.products')->whereIn('sku', ['CRO-001', 'SNP-001'])->get();
            foreach ($pastryProds as $pp) {
                DB::table('POS.pos_product_kds_routing')->updateOrInsert(
                    ['product_id' => $pp->id, 'kds_station_id' => $kdsPastryId]
                );
            }
        }

        // 4. Favorite Items for ALL terminals
        $allTerminals = DB::table('POS.pos_terminals')->get();
        $favSkus = ['ESP-001', 'CAP-001', 'TEA-001', 'WAT-001', 'NAS-001', 'MIE-001', 'CRO-001', 'SND-001'];
        foreach ($allTerminals as $term) {
            $order = 1;
            foreach ($favSkus as $sku) {
                $p = DB::table('INVENTORY.products')->where('sku', $sku)->first();
                if ($p) {
                    DB::table('POS.pos_favorite_items')->updateOrInsert(
                        [
                            'terminal_id' => $term->id,
                            'product_id' => $p->id,
                        ],
                        [
                            'sort_order' => $order++,
                        ]
                    );
                }
            }
        }

        // 5. Modifier Groups & Modifiers
        $modGroup1 = DB::table('POS.pos_modifier_groups')->where('name', 'Pilihan Topping Kopi')->first();
        if (!$modGroup1) {
            $grp1Id = DB::table('POS.pos_modifier_groups')->insertGetId([
                'name' => 'Pilihan Topping Kopi',
                'selection_type' => 'optional',
                'min_selections' => 0,
                'max_selections' => 3,
            ]);
        } else {
            $grp1Id = $modGroup1->id;
        }

        $modifiers1 = [
            ['name' => 'Extra Espresso Shot', 'price_delta' => 6000, 'replaces_base_price' => false],
            ['name' => 'Boba Brown Sugar', 'price_delta' => 5000, 'replaces_base_price' => false],
            ['name' => 'Oat Milk Upgrade', 'price_delta' => 7000, 'replaces_base_price' => false],
        ];
        foreach ($modifiers1 as $m) {
            DB::table('POS.pos_modifiers')->updateOrInsert(
                ['group_id' => $grp1Id, 'name' => $m['name']],
                ['price_delta' => $m['price_delta'], 'replaces_base_price' => $m['replaces_base_price']]
            );
        }

        // Attach coffee modifiers to coffee products
        $coffeeProds = DB::table('INVENTORY.products')->whereIn('sku', ['ESP-001', 'CAP-001'])->get();
        foreach ($coffeeProds as $cp) {
            DB::table('POS.pos_product_modifier_groups')->updateOrInsert(
                ['product_id' => $cp->id, 'group_id' => $grp1Id]
            );
        }

        $modGroup2 = DB::table('POS.pos_modifier_groups')->where('name', 'Tingkat Kepedasan')->first();
        if (!$modGroup2) {
            $grp2Id = DB::table('POS.pos_modifier_groups')->insertGetId([
                'name' => 'Tingkat Kepedasan',
                'selection_type' => 'single',
                'min_selections' => 1,
                'max_selections' => 1,
            ]);
        } else {
            $grp2Id = $modGroup2->id;
        }

        $modifiers2 = [
            ['name' => 'Tidak Pedas', 'price_delta' => 0, 'replaces_base_price' => false],
            ['name' => 'Sedang (Level 1)', 'price_delta' => 0, 'replaces_base_price' => false],
            ['name' => 'Pedas Mantap (Level 3)', 'price_delta' => 3000, 'replaces_base_price' => false],
        ];
        foreach ($modifiers2 as $m) {
            DB::table('POS.pos_modifiers')->updateOrInsert(
                ['group_id' => $grp2Id, 'name' => $m['name']],
                ['price_delta' => $m['price_delta'], 'replaces_base_price' => $m['replaces_base_price']]
            );
        }

        // Attach spicy modifiers to food products
        $foodProds = DB::table('INVENTORY.products')->whereIn('sku', ['NAS-001', 'MIE-001'])->get();
        foreach ($foodProds as $fp) {
            DB::table('POS.pos_product_modifier_groups')->updateOrInsert(
                ['product_id' => $fp->id, 'group_id' => $grp2Id]
            );
        }
    }

    private function seedAccounting(): void
    {
        // 1. Company
        $company = DB::table('ACCOUNTING.companies')->first();
        if (!$company) {
            $companyId = DB::table('ACCOUNTING.companies')->insertGetId([
                'legal_name' => 'PT Nusa Digital Solusindo',
                'npwp' => '01.234.567.8-012.000',
                'address' => 'Jl. HR Muhammad No. 88, Surabaya, Jawa Timur',
                'base_currency' => 'IDR',
                'fiscal_year_start_month' => 1,
                'coa_template_code' => 'ID_STANDARD',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $companyId = $company->id;
        }

        // 2. Fiscal Year
        $fy = DB::table('ACCOUNTING.fiscal_years')->where('company_id', $companyId)->where('year', 2026)->first();
        if (!$fy) {
            DB::table('ACCOUNTING.fiscal_years')->insert([
                'company_id' => $companyId,
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
            ]);
        }

        // 3. Accounts / COA
        $accounts = [
            ['code' => '1101', 'name' => 'Kas Kasir & Petty Cash', 'type' => 'asset', 'balance' => 'debit', 'control' => false],
            ['code' => '1102', 'name' => 'Bank BCA Operasional', 'type' => 'asset', 'balance' => 'debit', 'control' => false],
            ['code' => '1103', 'name' => 'Bank Mandiri Operasional', 'type' => 'asset', 'balance' => 'debit', 'control' => false],
            ['code' => '1104', 'name' => 'Piutang Usaha (Trade Receivables)', 'type' => 'asset', 'balance' => 'debit', 'control' => true],
            ['code' => '1105', 'name' => 'Persediaan Barang Dagang (Inventory)', 'type' => 'asset', 'balance' => 'debit', 'control' => true],
            ['code' => '2101', 'name' => 'Hutang Usaha (Trade Payables)', 'type' => 'liability', 'balance' => 'credit', 'control' => true],
            ['code' => '2102', 'name' => 'Hutang PPN Keluaran', 'type' => 'liability', 'balance' => 'credit', 'control' => false],
            ['code' => '2103', 'name' => 'Hutang Gaji & Tunjangan Karyawan', 'type' => 'liability', 'balance' => 'credit', 'control' => true],
            ['code' => '3101', 'name' => 'Modal Saham Disetor', 'type' => 'equity', 'balance' => 'credit', 'control' => false],
            ['code' => '3201', 'name' => 'Laba Ditahan', 'type' => 'equity', 'balance' => 'credit', 'control' => false],
            ['code' => '4101', 'name' => 'Pendapatan Penjualan Kasir POS', 'type' => 'revenue', 'balance' => 'credit', 'control' => false],
            ['code' => '4102', 'name' => 'Pendapatan Penjualan Grosir B2B', 'type' => 'revenue', 'balance' => 'credit', 'control' => false],
            ['code' => '5101', 'name' => 'Harga Pokok Penjualan (COGS)', 'type' => 'expense', 'balance' => 'debit', 'control' => false],
            ['code' => '6101', 'name' => 'Beban Gaji & Upah Karyawan', 'type' => 'expense', 'balance' => 'debit', 'control' => false],
            ['code' => '6102', 'name' => 'Beban Listrik, Air & Internet', 'type' => 'expense', 'balance' => 'debit', 'control' => false],
            ['code' => '6103', 'name' => 'Beban Sewa & Pemeliharaan', 'type' => 'expense', 'balance' => 'debit', 'control' => false],
        ];

        foreach ($accounts as $acc) {
            DB::table('ACCOUNTING.accounts')->updateOrInsert(
                ['company_id' => $companyId, 'account_code' => $acc['code']],
                [
                    'account_name' => $acc['name'],
                    'account_type' => $acc['type'],
                    'normal_balance' => $acc['balance'],
                    'is_control_account' => $acc['control'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Tax Codes
        $taxGl = DB::table('ACCOUNTING.accounts')->where('company_id', $companyId)->where('account_code', '2102')->value('id');
        if ($taxGl) {
            DB::table('ACCOUNTING.tax_codes')->updateOrInsert(
                ['code' => 'PPN11'],
                [
                    'company_id' => $companyId,
                    'rate' => 11.00,
                    'tax_type' => 'vat',
                    'gl_account_id' => $taxGl,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedCrm(): void
    {
        // 1. Partners with Logos
        $partners = [
            [
                'name' => 'PT Bank Central Asia Tbk',
                'trade_name' => 'BCA',
                'type' => 'customer',
                'source' => 'referral',
                'logo_url' => '/images/partners/partner-bca.svg',
            ],
            [
                'name' => 'PT Bank Mandiri (Persero) Tbk',
                'trade_name' => 'Bank Mandiri',
                'type' => 'customer',
                'source' => 'direct',
                'logo_url' => '/images/partners/partner-mandiri.svg',
            ],
            [
                'name' => 'PT Telekomunikasi Selular',
                'trade_name' => 'Telkomsel',
                'type' => 'customer',
                'source' => 'outbound',
                'logo_url' => '/images/partners/partner-telkom.svg',
            ],
            [
                'name' => 'PT Pertamina Retail',
                'trade_name' => 'Pertamina Retail',
                'type' => 'customer',
                'source' => 'referral',
                'logo_url' => '/images/partners/partner-pertamina.svg',
            ],
            [
                'name' => 'PT Astra International Tbk',
                'trade_name' => 'Astra',
                'type' => 'customer',
                'source' => 'direct',
                'logo_url' => '/images/partners/partner-astra.svg',
            ],
        ];

        foreach ($partners as $p) {
            DB::table('CRM.partners')->updateOrInsert(
                ['name' => $p['name']],
                [
                    'uuid' => (string) Str::uuid(),
                    'trade_name' => $p['trade_name'],
                    'type' => $p['type'],
                    'source' => $p['source'],
                    'logo_url' => $p['logo_url'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 2. Leads
        $leads = [
            [
                'name' => 'Pengadaan POS & Barcode 15 Outlet Retail',
                'company_name' => 'PT Retail Modern Nusantara',
                'stage' => 'proposal',
                'estimated_value' => 125000000,
                'notes' => 'Klien butuh integrasi kasir offline-first dan printer thermal bluetooth.',
            ],
            [
                'name' => 'Implementasi ERP Resto & KDS Kitchen',
                'company_name' => 'Kopi Kenangan Senja Cafe Group',
                'stage' => 'qualified',
                'estimated_value' => 85000000,
                'notes' => 'Memerlukan layar display pesanan dapur (KDS) dan denah meja 2 lantai.',
            ],
            [
                'name' => 'Software Payroll BPJS & Pajak PPh 21 Terintegrasi',
                'company_name' => 'PT Logistik Cepat Sentosa',
                'stage' => 'contacted',
                'estimated_value' => 60000000,
                'notes' => '200 karyawan shift warehouse dan driver distribusi.',
            ],
            [
                'name' => 'Migrasi Sistem Akuntansi ke Coretax DJP',
                'company_name' => 'CV Mega Baja Konstruksi',
                'stage' => 'new',
                'estimated_value' => 45000000,
                'notes' => 'Persiapan kepatuhan pelaporan e-Faktur Coretax terbaru.',
            ],
        ];

        foreach ($leads as $l) {
            DB::table('CRM.leads')->updateOrInsert(
                ['name' => $l['name']],
                [
                    'company_name' => $l['company_name'],
                    'stage' => $l['stage'],
                    'estimated_value' => $l['estimated_value'],
                    'notes' => $l['notes'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 3. Service Cases & Helpdesk Tickets
        $firstPartner = DB::table('CRM.partners')->first();
        if ($firstPartner) {
            DB::table('CRM.svc_cases')->updateOrInsert(
                ['subject' => 'Bantuan Integrasi QRIS Statis di Kasir POS'],
                [
                    'partner_id' => $firstPartner->id,
                    'priority' => 'medium',
                    'status' => 'in_progress',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('CRM.hd_tickets')->updateOrInsert(
                ['subject' => 'Printer Kasir Thermal Tidak Mencetak Kertas Struk'],
                [
                    'partner_id' => $firstPartner->id,
                    'requester_name' => 'Budi Kasir',
                    'requester_contact' => 'budi@toko.com',
                    'priority' => 'high',
                    'status' => 'open',
                    'channel' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedHcmAvatars(): void
    {
        $avatars = [
            '/images/avatars/avatar-1.svg',
            '/images/avatars/avatar-2.svg',
            '/images/avatars/avatar-3.svg',
            '/images/avatars/avatar-4.svg',
            '/images/avatars/avatar-5.svg',
        ];

        $employees = DB::table('HCM.employees')->get();
        foreach ($employees as $idx => $emp) {
            $avatar = $avatars[$idx % count($avatars)];
            DB::table('HCM.employees')->where('id', $emp->id)->update([
                'avatar_url' => $avatar,
            ]);
        }
    }

    private function seedProjects(): void
    {
        $projects = [
            [
                'code' => 'PRJ-POS-2026',
                'name' => 'Rollout POS & KDS Resto Multi-Cabang',
                'description' => 'Implementasi mesin kasir touch screen, display kitchen KDS, dan sinkronisasi data.',
                'status' => 'active',
            ],
            [
                'code' => 'PRJ-CORETAX-01',
                'name' => 'Kepatuhan E-Faktur Coretax DJP 2026',
                'description' => 'Penyesuaian format ekspor pajak pertambahan nilai sesuai regulasi DJP terbaru.',
                'status' => 'active',
            ],
        ];

        foreach ($projects as $prj) {
            $existingPrj = DB::table('PROJECTS.projects')->where('code', $prj['code'])->first();
            if (!$existingPrj) {
                DB::table('PROJECTS.projects')->insert([
                    'uuid' => (string) Str::uuid(),
                    'code' => $prj['code'],
                    'name' => $prj['name'],
                    'description' => $prj['description'],
                    'status' => $prj['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('PROJECTS.projects')->where('id', $existingPrj->id)->update([
                    'name' => $prj['name'],
                    'description' => $prj['description'],
                    'status' => $prj['status'],
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedPerformance(): void
    {
        $adminUser = DB::table('users')->where('email', 'admin@nusaevo.com')->first() ?? DB::table('users')->first();
        $adminId = $adminUser?->id;

        // 1. Perspectives
        $perspectives = [
            ['name' => 'Financial Growth', 'description' => 'Target pendapatan, efisiensi biaya, dan pertumbuhan profit margin.'],
            ['name' => 'Customer Satisfaction', 'description' => 'Kualitas layanan, loyalitas pelanggan B2B/Retail, dan SLA.'],
            ['name' => 'Operational Excellence', 'description' => 'Produktivitas pabrik, efisiensi gudang, dan pengurangan defect/waste.'],
            ['name' => 'Learning & Innovation', 'description' => 'Pengembangan SDM, sertifikasi staf, dan adopsi modul ERP.'],
        ];
        $perspIds = [];
        foreach ($perspectives as $p) {
            $existing = DB::table('PERF.perspectives')->where('name', $p['name'])->first();
            if (!$existing) {
                $id = DB::table('PERF.perspectives')->insertGetId([
                    'name' => $p['name'],
                    'description' => $p['description'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $perspIds[$p['name']] = $id;
            } else {
                $perspIds[$p['name']] = $existing->id;
            }
        }

        // 2. Periods
        $periods = [
            ['label' => 'FY 2026', 'period_type' => 'year', 'year' => 2026, 'quarter' => null, 'month' => null, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
            ['label' => 'Q1 2026', 'period_type' => 'quarter', 'year' => 2026, 'quarter' => 1, 'month' => null, 'start_date' => '2026-01-01', 'end_date' => '2026-03-31'],
            ['label' => 'Q2 2026', 'period_type' => 'quarter', 'year' => 2026, 'quarter' => 2, 'month' => null, 'start_date' => '2026-04-01', 'end_date' => '2026-06-30'],
            ['label' => 'Q3 2026', 'period_type' => 'quarter', 'year' => 2026, 'quarter' => 3, 'month' => null, 'start_date' => '2026-07-01', 'end_date' => '2026-09-30'],
        ];
        $periodIds = [];
        foreach ($periods as $pd) {
            $existing = DB::table('PERF.periods')->where('label', $pd['label'])->first();
            if (!$existing) {
                $id = DB::table('PERF.periods')->insertGetId(array_merge($pd, [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $periodIds[$pd['label']] = $id;
            } else {
                $periodIds[$pd['label']] = $existing->id;
            }
        }

        // 3. KPI Definitions
        $kpis = [
            [
                'name' => 'Pertumbuhan Omzet Penjualan (Revenue Growth)',
                'unit' => 'percent',
                'direction' => 'higher_is_better',
                'perspective_id' => $perspIds['Financial Growth'] ?? null,
                'description' => 'Persentase pertumbuhan pendapatan kotor dibandingkan periode sebelumnya.',
            ],
            [
                'name' => 'Skor Kepuasan Klien (CSAT)',
                'unit' => 'number',
                'direction' => 'higher_is_better',
                'perspective_id' => $perspIds['Customer Satisfaction'] ?? null,
                'description' => 'Indeks kepuasan pelanggan skala 1-100 dari umpan balik survei.',
            ],
            [
                'name' => 'Order Fulfillment Cycle Time',
                'unit' => 'number',
                'direction' => 'lower_is_better',
                'perspective_id' => $perspIds['Operational Excellence'] ?? null,
                'description' => 'Rata-rata waktu siklus dari Sales Order hingga barang diterima pelanggan (hari).',
            ],
            [
                'name' => 'Tingkat Defect Produksi Pabrik (Scrap Rate)',
                'unit' => 'percent',
                'direction' => 'lower_is_better',
                'perspective_id' => $perspIds['Operational Excellence'] ?? null,
                'description' => 'Persentase barang cacat atau limbah dalam proses manufaktur.',
            ],
            [
                'name' => 'Employee Training Hours',
                'unit' => 'number',
                'direction' => 'higher_is_better',
                'perspective_id' => $perspIds['Learning & Innovation'] ?? null,
                'description' => 'Rata-rata jam pelatihan kompetensi per karyawan per tahun.',
            ],
        ];

        $kpiIds = [];
        foreach ($kpis as $kpi) {
            $existing = DB::table('PERF.kpi_definitions')->where('name', $kpi['name'])->first();
            if (!$existing) {
                $id = DB::table('PERF.kpi_definitions')->insertGetId(array_merge($kpi, [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $kpiIds[$kpi['name']] = $id;
            } else {
                $kpiIds[$kpi['name']] = $existing->id;
            }
        }

        // 4. Targets & Values
        $targetData = [
            ['kpi' => 'Pertumbuhan Omzet Penjualan (Revenue Growth)', 'period' => 'FY 2026', 'target' => 25.0, 'stretch' => 30.0, 'actual' => 27.5],
            ['kpi' => 'Skor Kepuasan Klien (CSAT)', 'period' => 'Q1 2026', 'target' => 90.0, 'stretch' => 95.0, 'actual' => 92.4],
            ['kpi' => 'Order Fulfillment Cycle Time', 'period' => 'Q1 2026', 'target' => 2.0, 'stretch' => 1.5, 'actual' => 1.8],
            ['kpi' => 'Tingkat Defect Produksi Pabrik (Scrap Rate)', 'period' => 'Q1 2026', 'target' => 2.0, 'stretch' => 1.0, 'actual' => 1.4],
            ['kpi' => 'Employee Training Hours', 'period' => 'FY 2026', 'target' => 40.0, 'stretch' => 50.0, 'actual' => 42.0],
        ];

        foreach ($targetData as $td) {
            $kId = $kpiIds[$td['kpi']] ?? null;
            $pId = $periodIds[$td['period']] ?? null;
            if ($kId && $pId) {
                DB::table('PERF.targets')->updateOrInsert(
                    [
                        'kpi_id' => $kId,
                        'subject_type' => 'company',
                        'subject_id' => null,
                        'period_id' => $pId,
                    ],
                    [
                        'target_value' => $td['target'],
                        'stretch_value' => $td['stretch'],
                        'notes' => 'Target korporat per rencana kerja tahunan 2026.',
                        'created_by' => $adminId,
                        'updated_at' => now(),
                    ]
                );

                DB::table('PERF.kpi_values')->updateOrInsert(
                    [
                        'kpi_id' => $kId,
                        'subject_type' => 'company',
                        'subject_id' => null,
                        'period_id' => $pId,
                    ],
                    [
                        'actual_value' => $td['actual'],
                        'source' => 'manual',
                        'entered_by' => $adminId,
                        'entered_at' => now(),
                    ]
                );
            }
        }

        // 5. OKR Cycles & Objectives
        $cycle = DB::table('PERF.okr_cycles')->where('label', 'Siklus OKR 2026')->first();
        if (!$cycle) {
            $cycleId = DB::table('PERF.okr_cycles')->insertGetId([
                'label' => 'Siklus OKR 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $cycleId = $cycle->id;
        }

        $objectives = [
            [
                'text' => 'Akselerasi Efisiensi Produksi dan Pengiriman Pesanan Konsumen',
                'status' => 'on_track',
                'krs' => [
                    ['desc' => 'Tingkatkan akurasi pemenuhan pesanan (OTIF) hingga 98%', 'metric_type' => 'percent', 'start' => 90, 'current' => 96, 'target' => 98, 'weight' => 100],
                    ['desc' => 'Kurangi lead time roasting biji kopi dari 3 hari menjadi 1 hari', 'metric_type' => 'numeric', 'start' => 3, 'current' => 1.5, 'target' => 1, 'weight' => 100],
                ],
            ],
            [
                'text' => 'Ekspansi Jaringan Retail POS dan Pelayanan Restoran Premium',
                'status' => 'on_track',
                'krs' => [
                    ['desc' => 'Implementasi sistem kasir POS & KDS di seluruh cabang aktif', 'metric_type' => 'boolean', 'start' => 0, 'current' => 1, 'target' => 1, 'weight' => 100],
                    ['desc' => 'Tingkatkan rata-rata nilai transaksi (basket size) sebesar 20%', 'metric_type' => 'percent', 'start' => 0, 'current' => 15, 'target' => 20, 'weight' => 100],
                ],
            ],
        ];

        foreach ($objectives as $obj) {
            $existingObj = DB::table('PERF.okr_objectives')->where('cycle_id', $cycleId)->where('objective_text', $obj['text'])->first();
            if (!$existingObj) {
                $objId = DB::table('PERF.okr_objectives')->insertGetId([
                    'cycle_id' => $cycleId,
                    'subject_type' => 'company',
                    'subject_id' => null,
                    'objective_text' => $obj['text'],
                    'status' => $obj['status'],
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $objId = $existingObj->id;
            }

            foreach ($obj['krs'] as $kr) {
                DB::table('PERF.okr_key_results')->updateOrInsert(
                    [
                        'okr_id' => $objId,
                        'description' => $kr['desc'],
                    ],
                    [
                        'metric_type' => $kr['metric_type'],
                        'start_value' => $kr['start'],
                        'current_value' => $kr['current'],
                        'target_value' => $kr['target'],
                        'weight' => $kr['weight'],
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 6. Badges & Achievements
        $badges = [
            ['name' => 'Operational Hero', 'trigger_type' => 'target_hit', 'icon' => 'Award'],
            ['name' => 'Zero Defect Master', 'trigger_type' => 'target_hit', 'icon' => 'ShieldCheck'],
            ['name' => 'Sprint Finisher', 'trigger_type' => 'okr_completed', 'icon' => 'Trophy'],
        ];

        $badgeIds = [];
        foreach ($badges as $b) {
            $existingB = DB::table('PERF.badge_definitions')->where('name', $b['name'])->first();
            if (!$existingB) {
                $bId = DB::table('PERF.badge_definitions')->insertGetId([
                    'name' => $b['name'],
                    'trigger_type' => $b['trigger_type'],
                    'icon' => $b['icon'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $badgeIds[$b['name']] = $bId;
            } else {
                $badgeIds[$b['name']] = $existingB->id;
            }
        }

        $emp = DB::table('HCM.employees')->first();
        if ($emp && !empty($badgeIds)) {
            $firstBadgeId = reset($badgeIds);
            $hasAch = DB::table('PERF.achievements')->where('badge_id', $firstBadgeId)->where('subject_id', $emp->id)->exists();
            if (!$hasAch) {
                DB::table('PERF.achievements')->insert([
                    'subject_type' => 'employee',
                    'subject_id' => $emp->id,
                    'badge_id' => $firstBadgeId,
                    'kpi_id' => null,
                    'okr_id' => null,
                    'period_id' => $periodIds['Q1 2026'] ?? null,
                    'earned_at' => now()->subDays(5),
                    'awarded_by' => $adminId,
                ]);
            }
        }

        // 7. Budget
        $bgtPeriodId = $periodIds['FY 2026'] ?? reset($periodIds);
        if ($bgtPeriodId) {
            $existingBgt = DB::table('PERF.budget_hdrs')->where('name', 'Rencana Anggaran Operasional 2026')->first();
            if (!$existingBgt) {
                $bgtId = DB::table('PERF.budget_hdrs')->insertGetId([
                    'name' => 'Rencana Anggaran Operasional 2026',
                    'subject_type' => 'company',
                    'subject_id' => null,
                    'fiscal_year' => 2026,
                    'fiscal_quarter' => null,
                    'status' => 'approved',
                    'owner_id' => $adminId,
                    'version_no' => 1,
                    'notes' => 'Anggaran operasional tahunan yang telah disetujui direksi.',
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $lines = [
                    ['category' => 'Biaya Produksi & Bahan Baku', 'amount' => 250000000],
                    ['category' => 'Gaji & Operasional Karyawan', 'amount' => 480000000],
                    ['category' => 'Pengembangan IT & Lisensi Cloud', 'amount' => 120000000],
                    ['category' => 'Logistik & Pemeliharaan Mesin', 'amount' => 75000000],
                ];

                foreach ($lines as $line) {
                    DB::table('PERF.budget_lines')->insert([
                        'budget_id' => $bgtId,
                        'category' => $line['category'],
                        'period_id' => $bgtPeriodId,
                        'amount_planned' => $line['amount'],
                        'notes' => 'Plafon alokasi ' . $line['category'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function seedPp(): void
    {
        $adminUser = DB::table('users')->where('email', 'admin@nusaevo.com')->first() ?? DB::table('users')->first();
        $adminId = $adminUser?->id;

        $uomKg = DB::table('INVENTORY.uoms')->where('code', 'KG')->first();
        $uomL = DB::table('INVENTORY.uoms')->where('code', 'L')->first();
        $uomPcs = DB::table('INVENTORY.uoms')->where('code', 'PCS')->first();
        $uomCup = DB::table('INVENTORY.uoms')->where('code', 'CUP')->first();
        $baseUomId = $uomPcs?->id ?? 1;

        $catRaw = DB::table('INVENTORY.product_categories')->where('name', 'Bahan Baku & Mentah')->first();
        $catRawId = $catRaw?->id ?? DB::table('INVENTORY.product_categories')->insertGetId([
            'name' => 'Bahan Baku & Mentah',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Raw materials
        $raws = [
            ['sku' => 'RAW-COF-01', 'name' => 'Biji Kopi Hijau Arabika Gayo', 'uom_id' => $uomKg?->id ?? $baseUomId],
            ['sku' => 'RAW-MLK-01', 'name' => 'Susu Segar Murni Pasteurised', 'uom_id' => $uomL?->id ?? $baseUomId],
            ['sku' => 'RAW-CUP-01', 'name' => 'Paper Cup Takeaway 8oz + Lid', 'uom_id' => $uomCup?->id ?? $baseUomId],
            ['sku' => 'RAW-RIC-01', 'name' => 'Beras Premium Rojolele Super', 'uom_id' => $uomKg?->id ?? $baseUomId],
            ['sku' => 'PKG-BOX-01', 'name' => 'Kotak Dus Makanan Bento Eco', 'uom_id' => $uomPcs?->id ?? $baseUomId],
        ];

        $rawMap = [];
        foreach ($raws as $r) {
            $existing = DB::table('INVENTORY.products')->where('sku', $r['sku'])->first();
            if (!$existing) {
                $id = DB::table('INVENTORY.products')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'sku' => $r['sku'],
                    'name' => $r['name'],
                    'description' => 'Bahan baku produksi standar pabrik & dapur.',
                    'category_id' => $catRawId,
                    'base_uom_id' => $r['uom_id'],
                    'costing_method' => 'fifo',
                    'tracking_mode' => 'none',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $rawMap[$r['sku']] = $id;
            } else {
                $rawMap[$r['sku']] = $existing->id;
            }
        }

        $prodEsp = DB::table('INVENTORY.products')->where('sku', 'ESP-001')->first();
        $prodCap = DB::table('INVENTORY.products')->where('sku', 'CAP-001')->first();
        $prodNas = DB::table('INVENTORY.products')->where('sku', 'NAS-001')->first();

        // 1. BOMs
        if ($prodEsp && isset($rawMap['RAW-COF-01'], $rawMap['RAW-CUP-01'])) {
            $bomEsp = DB::table('PP.pp_boms')->where('product_id', $prodEsp->id)->where('is_active', true)->first();
            if (!$bomEsp) {
                $bomEspId = DB::table('PP.pp_boms')->insertGetId([
                    'product_id' => $prodEsp->id,
                    'version' => 1,
                    'effective_from' => now()->subDays(30)->toDateString(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('PP.pp_bom_lines')->insert([
                    ['bom_id' => $bomEspId, 'component_product_id' => $rawMap['RAW-COF-01'], 'qty_per_parent_unit' => 0.018000, 'uom_code' => 'KG', 'scrap_pct' => 2.0],
                    ['bom_id' => $bomEspId, 'component_product_id' => $rawMap['RAW-CUP-01'], 'qty_per_parent_unit' => 1.000000, 'uom_code' => 'CUP', 'scrap_pct' => 1.0],
                ]);
            }
        }

        if ($prodCap && isset($rawMap['RAW-COF-01'], $rawMap['RAW-MLK-01'], $rawMap['RAW-CUP-01'])) {
            $bomCap = DB::table('PP.pp_boms')->where('product_id', $prodCap->id)->where('is_active', true)->first();
            if (!$bomCap) {
                $bomCapId = DB::table('PP.pp_boms')->insertGetId([
                    'product_id' => $prodCap->id,
                    'version' => 1,
                    'effective_from' => now()->subDays(30)->toDateString(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('PP.pp_bom_lines')->insert([
                    ['bom_id' => $bomCapId, 'component_product_id' => $rawMap['RAW-COF-01'], 'qty_per_parent_unit' => 0.018000, 'uom_code' => 'KG', 'scrap_pct' => 2.0],
                    ['bom_id' => $bomCapId, 'component_product_id' => $rawMap['RAW-MLK-01'], 'qty_per_parent_unit' => 0.150000, 'uom_code' => 'L', 'scrap_pct' => 3.0],
                    ['bom_id' => $bomCapId, 'component_product_id' => $rawMap['RAW-CUP-01'], 'qty_per_parent_unit' => 1.000000, 'uom_code' => 'CUP', 'scrap_pct' => 1.0],
                ]);
            }
        }

        if ($prodNas && isset($rawMap['RAW-RIC-01'], $rawMap['PKG-BOX-01'])) {
            $bomNas = DB::table('PP.pp_boms')->where('product_id', $prodNas->id)->where('is_active', true)->first();
            if (!$bomNas) {
                $bomNasId = DB::table('PP.pp_boms')->insertGetId([
                    'product_id' => $prodNas->id,
                    'version' => 1,
                    'effective_from' => now()->subDays(30)->toDateString(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('PP.pp_bom_lines')->insert([
                    ['bom_id' => $bomNasId, 'component_product_id' => $rawMap['RAW-RIC-01'], 'qty_per_parent_unit' => 0.200000, 'uom_code' => 'KG', 'scrap_pct' => 1.0],
                    ['bom_id' => $bomNasId, 'component_product_id' => $rawMap['PKG-BOX-01'], 'qty_per_parent_unit' => 1.000000, 'uom_code' => 'PCS', 'scrap_pct' => 0.0],
                ]);
            }
        }

        // 2. Recipes
        if ($prodEsp && isset($rawMap['RAW-COF-01'], $rawMap['RAW-CUP-01'])) {
            $recipe = DB::table('PP.pp_recipes')->where('product_id', $prodEsp->id)->where('is_active', true)->first();
            if (!$recipe) {
                $recId = DB::table('PP.pp_recipes')->insertGetId([
                    'product_id' => $prodEsp->id,
                    'version' => 1,
                    'batch_size' => 50.0,
                    'uom_code' => 'CUP',
                    'expected_yield_pct' => 100.0,
                    'expected_waste_pct' => 2.0,
                    'effective_from' => now()->subDays(30)->toDateString(),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('PP.pp_recipe_ingredients')->insert([
                    ['recipe_id' => $recId, 'raw_material_product_id' => $rawMap['RAW-COF-01'], 'qty_per_batch' => 0.900000, 'uom_code' => 'KG'],
                    ['recipe_id' => $recId, 'raw_material_product_id' => $rawMap['RAW-CUP-01'], 'qty_per_batch' => 50.000000, 'uom_code' => 'CUP'],
                ]);
            }
        }

        // 3. Planning Parameters
        $planProds = [$prodEsp, $prodCap, $prodNas];
        foreach ($planProds as $pp) {
            if ($pp) {
                DB::table('PP.pp_item_planning_params')->updateOrInsert(
                    ['product_id' => $pp->id],
                    [
                        'make_type' => 'mts',
                        'min_lot_qty' => 10.0,
                        'max_lot_qty' => 500.0,
                        'safety_stock_qty' => 25.0,
                        'lead_time_days' => 1,
                        'planning_lead_time_days' => 2,
                        'scrap_pct' => 2.0,
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 4. Resources
        $resources = [
            ['type' => 'tool', 'code' => 'RES-ROAST-01', 'name' => 'Industrial Drum Coffee Roaster 15kg', 'capacity' => 15.0, 'uom_code' => 'KG'],
            ['type' => 'utility', 'code' => 'RES-BREW-01', 'name' => 'Commercial High-Volume Espresso Machine', 'capacity' => 120.0, 'uom_code' => 'CUP'],
            ['type' => 'tank', 'code' => 'RES-SILO-01', 'name' => 'Silo Penyimpanan Biji Kopi Sangrai', 'capacity' => 200.0, 'uom_code' => 'KG'],
        ];
        foreach ($resources as $res) {
            DB::table('PP.pp_resources')->updateOrInsert(
                ['code' => $res['code']],
                array_merge($res, ['is_active' => true])
            );
        }

        // 5. Demand Forecast & Lines
        if ($prodEsp) {
            DB::table('PP.pp_demand_forecasts')->updateOrInsert(
                [
                    'product_id' => $prodEsp->id,
                    'period_start' => now()->startOfMonth()->toDateString(),
                ],
                [
                    'qty' => 1500.0,
                    'source' => 'manual',
                    'note' => 'Perkiraan konsumsi bulanan kafe & takeaway.',
                    'created_by' => $adminId,
                    'updated_at' => now(),
                ]
            );

            $dmdHdr = DB::table('PP.pp_demand_hdrs')->where('note', 'Demand Forecast Produksi F&B September 2026')->first();
            if (!$dmdHdr) {
                $dmdHdrId = DB::table('PP.pp_demand_hdrs')->insertGetId([
                    'source_type' => 'forecast',
                    'demand_date' => now()->toDateString(),
                    'note' => 'Demand Forecast Produksi F&B September 2026',
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('PP.pp_demand_lines')->insert([
                    'demand_hdr_id' => $dmdHdrId,
                    'product_id' => $prodEsp->id,
                    'need_by_date' => now()->addDays(7)->toDateString(),
                    'qty' => 300.0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 6. MRP Run & Planned Orders
        $mrpRun = DB::table('PP.pp_mrp_runs')->first();
        if (!$mrpRun) {
            $mrpRunId = DB::table('PP.pp_mrp_runs')->insertGetId([
                'run_at' => now(),
                'triggered_by' => $adminId,
                'status' => 'completed',
            ]);
        } else {
            $mrpRunId = $mrpRun->id;
        }

        if ($prodEsp) {
            $bomEspRow = DB::table('PP.pp_boms')->where('product_id', $prodEsp->id)->first();
            if ($bomEspRow) {
                $existingPlan = DB::table('PP.pp_planned_orders')->where('plan_number', 'PLAN-2026-0001')->first();
                if (!$existingPlan) {
                    $planId = DB::table('PP.pp_planned_orders')->insertGetId([
                        'mrp_run_id' => $mrpRunId,
                        'plan_number' => 'PLAN-2026-0001',
                        'order_type' => 'production',
                        'product_id' => $prodEsp->id,
                        'qty' => 100.0,
                        'need_by_date' => now()->addDays(3)->toDateString(),
                        'bom_id' => $bomEspRow->id,
                        'status' => 'firmed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('PP.pp_schedule_ops')->insert([
                        'planned_order_id' => $planId,
                        'seq' => 1,
                        'resource_type' => 'mes_work_center',
                        'planned_start' => now()->addHours(2),
                        'planned_end' => now()->addHours(4),
                        'status' => 'committed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function seedMes(): void
    {
        // 1. Work Centers
        $wcs = [
            ['code' => 'WC-COFFEE', 'name' => 'Line Pengolahan Minuman & Kopi', 'area_line' => 'Area Beverage Lantai 1', 'type' => 'discrete'],
            ['code' => 'WC-KITCHEN', 'name' => 'Dapur Produksi Makanan Panas', 'area_line' => 'Area Kitchen Pusat', 'type' => 'discrete'],
            ['code' => 'WC-PACK', 'name' => 'Area Pengemasan & Quality Control', 'area_line' => 'Area Packaging', 'type' => 'discrete'],
        ];

        $wcMap = [];
        foreach ($wcs as $w) {
            $existing = DB::table('MES.mes_work_centers')->where('code', $w['code'])->first();
            if (!$existing) {
                $id = DB::table('MES.mes_work_centers')->insertGetId(array_merge($w, ['created_at' => now(), 'updated_at' => now()]));
                $wcMap[$w['code']] = $id;
            } else {
                $wcMap[$w['code']] = $existing->id;
            }
        }

        // 2. Machines
        $machines = [
            ['code' => 'M-ESPRESSO-01', 'name' => 'Mesin Espresso Commercial 3-Group', 'work_center_id' => $wcMap['WC-COFFEE'], 'status' => 'running'],
            ['code' => 'M-ROASTER-01', 'name' => 'Mesin Roaster Drum Kopi 12kg', 'work_center_id' => $wcMap['WC-COFFEE'], 'status' => 'idle'],
            ['code' => 'M-WOK-01', 'name' => 'Kompor Wok Komersial High-Pressure 2-Tungku', 'work_center_id' => $wcMap['WC-KITCHEN'], 'status' => 'running'],
            ['code' => 'M-SEALER-01', 'name' => 'Mesin Cup Sealer Otomatis Digital', 'work_center_id' => $wcMap['WC-PACK'], 'status' => 'running'],
        ];

        $machineMap = [];
        foreach ($machines as $m) {
            $existing = DB::table('MES.mes_machines')->where('code', $m['code'])->first();
            if (!$existing) {
                $id = DB::table('MES.mes_machines')->insertGetId(array_merge($m, ['created_at' => now(), 'updated_at' => now()]));
                $machineMap[$m['code']] = $id;
            } else {
                $machineMap[$m['code']] = $existing->id;
            }
        }

        // 3. Stations
        $stations = [
            ['code' => 'ST-BEV-01', 'name' => 'Station Barista Utama', 'work_center_id' => $wcMap['WC-COFFEE'], 'machine_id' => $machineMap['M-ESPRESSO-01'] ?? null],
            ['code' => 'ST-COOK-01', 'name' => 'Station Chef Wok Dapur', 'work_center_id' => $wcMap['WC-KITCHEN'], 'machine_id' => $machineMap['M-WOK-01'] ?? null],
            ['code' => 'ST-PACK-01', 'name' => 'Meja Packaging & Sealing', 'work_center_id' => $wcMap['WC-PACK'], 'machine_id' => $machineMap['M-SEALER-01'] ?? null],
        ];

        foreach ($stations as $st) {
            DB::table('MES.mes_stations')->updateOrInsert(
                ['code' => $st['code']],
                ['name' => $st['name'], 'work_center_id' => $st['work_center_id'], 'machine_id' => $st['machine_id']]
            );
        }

        // 4. Routings
        $prodEsp = DB::table('INVENTORY.products')->where('sku', 'ESP-001')->first();
        if ($prodEsp && isset($wcMap['WC-COFFEE'], $wcMap['WC-PACK'])) {
            $routing = DB::table('MES.mes_routings')->where('product_id', $prodEsp->id)->where('is_active', true)->first();
            if (!$routing) {
                $rId = DB::table('MES.mes_routings')->insertGetId([
                    'product_id' => $prodEsp->id,
                    'version' => 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('MES.mes_routing_ops')->insert([
                    ['routing_id' => $rId, 'seq' => 10, 'op_code' => 'OP-GRIND', 'op_name' => 'Penggilingan Biji Kopi Presisi', 'work_center_id' => $wcMap['WC-COFFEE'], 'setup_time_minutes' => 2, 'run_time_minutes' => 3, 'queue_time_minutes' => 0, 'standard_output_qty' => 50, 'instructions' => 'Giling 18g biji kopi per shot.'],
                    ['routing_id' => $rId, 'seq' => 20, 'op_code' => 'OP-BREW', 'op_name' => 'Ekstraksi Tekanan 9 Bar Espresso', 'work_center_id' => $wcMap['WC-COFFEE'], 'setup_time_minutes' => 1, 'run_time_minutes' => 2, 'queue_time_minutes' => 0, 'standard_output_qty' => 50, 'instructions' => 'Waktu ekstraksi 25-30 detik.'],
                    ['routing_id' => $rId, 'seq' => 30, 'op_code' => 'OP-QC', 'op_name' => 'Inspeksi Kualitas Crema & Cup Sealing', 'work_center_id' => $wcMap['WC-PACK'], 'setup_time_minutes' => 1, 'run_time_minutes' => 1, 'queue_time_minutes' => 0, 'standard_output_qty' => 50, 'instructions' => 'Pastikan aroma optimal dan cup tertutup rapat.'],
                ]);
            }
        }

        // 5. Production Orders
        if ($prodEsp) {
            $bomEsp = DB::table('PP.pp_boms')->where('product_id', $prodEsp->id)->first();
            $recipeEsp = DB::table('PP.pp_recipes')->where('product_id', $prodEsp->id)->first();
            $routingEsp = DB::table('MES.mes_routings')->where('product_id', $prodEsp->id)->first();

            if ($bomEsp) {
                // Completed Order
                $moDone = DB::table('MES.mes_prod_order_hdrs')->where('order_number', 'MO-2026-0001')->first();
                if (!$moDone) {
                    $moDoneId = DB::table('MES.mes_prod_order_hdrs')->insertGetId([
                        'order_number' => 'MO-2026-0001',
                        'product_id' => $prodEsp->id,
                        'production_model' => 'assembly',
                        'bom_id' => $bomEsp->id,
                        'routing_id' => $routingEsp?->id,
                        'qty' => 50.0,
                        'uom_code' => 'CUP',
                        'planned_start' => now()->subHours(6),
                        'planned_end' => now()->subHours(4),
                        'actual_start' => now()->subHours(6),
                        'actual_end' => now()->subHours(4),
                        'priority' => 'normal',
                        'status' => 'completed',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('MES.mes_production_outputs')->insert([
                        'order_id' => $moDoneId,
                        'output_type' => 'finished',
                        'product_id' => $prodEsp->id,
                        'qty' => 50.0,
                        'uom_code' => 'CUP',
                        'created_at' => now()->subHours(4),
                    ]);
                    DB::table('MES.mes_production_outputs')->insert([
                        'order_id' => $moDoneId,
                        'output_type' => 'waste',
                        'product_id' => $prodEsp->id,
                        'qty' => 2.0,
                        'uom_code' => 'CUP',
                        'reason_code' => 'tamping_defect',
                        'disposition' => 'scrap',
                        'created_at' => now()->subHours(4),
                    ]);

                    if ($recipeEsp) {
                        DB::table('MES.mes_batches')->updateOrInsert(
                            ['batch_number' => 'BATCH-202609-001'],
                            [
                                'order_id' => $moDoneId,
                                'recipe_id' => $recipeEsp->id,
                                'status' => 'completed',
                                'planned_qty' => 50.0,
                                'actual_yield_pct' => 100.0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }

                // In-Progress Order
                $moWip = DB::table('MES.mes_prod_order_hdrs')->where('order_number', 'MO-2026-0002')->first();
                if (!$moWip) {
                    DB::table('MES.mes_prod_order_hdrs')->insertGetId([
                        'order_number' => 'MO-2026-0002',
                        'product_id' => $prodEsp->id,
                        'production_model' => 'assembly',
                        'bom_id' => $bomEsp->id,
                        'routing_id' => $routingEsp?->id,
                        'qty' => 30.0,
                        'uom_code' => 'CUP',
                        'planned_start' => now()->subHours(1),
                        'planned_end' => now()->addHours(2),
                        'actual_start' => now()->subHours(1),
                        'priority' => 'high',
                        'status' => 'in_progress',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // 6. Downtime Event & Andon Alert
        if (isset($machineMap['M-ROASTER-01'])) {
            $hasDown = DB::table('MES.mes_downtime_events')->where('machine_id', $machineMap['M-ROASTER-01'])->exists();
            if (!$hasDown) {
                DB::table('MES.mes_downtime_events')->insert([
                    'machine_id' => $machineMap['M-ROASTER-01'],
                    'category' => 'unplanned',
                    'reason_code' => 'mechanical',
                    'started_at' => now()->subHours(4),
                    'ended_at' => now()->subHours(3)->subMinutes(40),
                ]);
            }
        }

        if (isset($machineMap['M-ESPRESSO-01'])) {
            $hasAlert = DB::table('MES.mes_andon_alerts')->where('subject_id', $machineMap['M-ESPRESSO-01'])->exists();
            if (!$hasAlert) {
                DB::table('MES.mes_andon_alerts')->insert([
                    'alert_type' => 'out_of_spec_parameter',
                    'subject_type' => 'MES.mes_machines',
                    'subject_id' => $machineMap['M-ESPRESSO-01'],
                    'severity' => 'warning',
                    'message' => 'Suhu boiler grup 2 sempat turun di bawah 90C.',
                    'fired_at' => now()->subHours(3),
                    'resolved_at' => now()->subHours(2)->subMinutes(30),
                ]);
            }
        }
    }

    private function seedWne(): void
    {
        $adminUser = DB::table('users')->where('email', 'admin@nusaevo.com')->first() ?? DB::table('users')->first();
        $adminId = $adminUser?->id;

        // 1. Workflow Categories
        $cats = [
            ['name' => 'Persetujuan Pembelian & Keuangan', 'description' => 'Alur verifikasi & otorisasi PO, AP Bill, dan budget expenditure.'],
            ['name' => 'Manajemen Sumber Daya Manusia', 'description' => 'Alur pengajuan cuti, reimbursement, dan onboarding karyawan.'],
            ['name' => 'Operasional & Manufaktur', 'description' => 'Eskalasi maintenance mesin, QC hold, dan penerimaan logistik.'],
        ];
        $catMap = [];
        foreach ($cats as $c) {
            $existing = DB::table('WNE.wrkflow_categories')->where('name', $c['name'])->first();
            if (!$existing) {
                $id = DB::table('WNE.wrkflow_categories')->insertGetId([
                    'name' => $c['name'],
                    'description' => $c['description'],
                    'is_active' => true,
                ]);
                $catMap[$c['name']] = $id;
            } else {
                $catMap[$c['name']] = $existing->id;
            }
        }

        // 2. Workflow Definitions & Versions
        $def = DB::table('WNE.wrkflow_definitions')->where('code', 'pur.po_approval')->first();
        if (!$def) {
            $defId = DB::table('WNE.wrkflow_definitions')->insertGetId([
                'code' => 'pur.po_approval',
                'name' => 'Alur Persetujuan Purchase Order Bernilai Tinggi',
                'description' => 'PO di atas Rp 10.000.000 wajib disetujui Manager Operasional & Keuangan.',
                'category_id' => $catMap['Persetujuan Pembelian & Keuangan'] ?? null,
                'status' => 'published',
                'created_by' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $verId = DB::table('WNE.wrkflow_versions')->insertGetId([
                'definition_id' => $defId,
                'version_no' => 1,
                'published_at' => now(),
                'published_by' => $adminId,
            ]);

            $step1 = DB::table('WNE.wrkflow_steps')->insertGetId([
                'version_id' => $verId,
                'step_code' => 'entry_po_draft',
                'type' => 'task',
                'config' => json_encode(['label' => 'Pembuatan Draft PO']),
                'is_entry_step' => true,
            ]);

            $step2 = DB::table('WNE.wrkflow_steps')->insertGetId([
                'version_id' => $verId,
                'step_code' => 'manager_review',
                'type' => 'approval',
                'config' => json_encode(['assignee_role' => 'Procurement Manager', 'sla_hours' => 24]),
                'is_entry_step' => false,
            ]);

            $step3 = DB::table('WNE.wrkflow_steps')->insertGetId([
                'version_id' => $verId,
                'step_code' => 'finance_approval',
                'type' => 'approval',
                'config' => json_encode(['assignee_role' => 'Finance Director', 'sla_hours' => 48]),
                'is_entry_step' => false,
            ]);

            $step4 = DB::table('WNE.wrkflow_steps')->insertGetId([
                'version_id' => $verId,
                'step_code' => 'notify_supplier',
                'type' => 'notify',
                'config' => json_encode(['channels' => ['email', 'in_app']]),
                'is_entry_step' => false,
            ]);

            DB::table('WNE.wrkflow_transitions')->insert([
                ['from_step_id' => $step1, 'to_step_id' => $step2, 'condition_expression' => null, 'seq' => 1],
                ['from_step_id' => $step2, 'to_step_id' => $step3, 'condition_expression' => null, 'seq' => 1],
                ['from_step_id' => $step3, 'to_step_id' => $step4, 'condition_expression' => null, 'seq' => 1],
            ]);

            // Create active workflow instance
            $instId = DB::table('WNE.wrkflow_instances')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'definition_version_id' => $verId,
                'subject_type' => 'purchase_order',
                'subject_id' => 1,
                'status' => 'running',
                'payload' => json_encode(['order_no' => 'PO-2026-001', 'total' => 25000000]),
                'started_by' => $adminId,
                'started_at' => now()->subHours(2),
            ]);

            DB::table('WNE.wrkflow_instance_steps')->insert([
                [
                    'instance_id' => $instId,
                    'step_id' => $step1,
                    'status' => 'completed',
                    'assigned_to' => $adminId,
                    'started_at' => now()->subHours(2),
                    'completed_at' => now()->subHours(2),
                    'decision' => 'submit',
                    'comment' => 'Pengajuan draft PO bahan baku kopi bulanan.',
                ],
                [
                    'instance_id' => $instId,
                    'step_id' => $step2,
                    'status' => 'in_progress',
                    'assigned_to' => $adminId,
                    'started_at' => now()->subHours(2),
                    'completed_at' => null,
                    'decision' => null,
                    'comment' => null,
                ],
            ]);
        }

        // 3. Notification Categories & Templates
        $msgCats = [
            ['code' => 'trans.po_approved', 'name' => 'Purchase Order Disetujui'],
            ['code' => 'hcm.leave_status', 'name' => 'Status Pengajuan Cuti'],
            ['code' => 'mes.machine_alert', 'name' => 'Peringatan Operasional Mesin Pabrik'],
        ];
        foreach ($msgCats as $mc) {
            DB::table('WNE.msg_categories')->updateOrInsert(
                ['code' => $mc['code']],
                ['name' => $mc['name'], 'is_mandatory' => false, 'default_channels' => json_encode(['in_app', 'email'])]
            );
        }

        // 4. Notifications & Deliveries
        if ($adminId) {
            $sampleNotifs = [
                [
                    'category_code' => 'trans.po_approved',
                    'recipient_type' => 'user',
                    'recipient_user_id' => $adminId,
                    'subject' => 'Purchase Order PO-2026-001 Siap Diproses',
                    'body' => 'Purchase Order untuk PT Supplier Pangan Makmur telah diverifikasi dan siap dikirimkan.',
                    'status' => 'sent',
                ],
                [
                    'category_code' => 'mes.machine_alert',
                    'recipient_type' => 'user',
                    'recipient_user_id' => $adminId,
                    'subject' => 'Pemberitahuan Mesin: M-ESPRESSO-01 Telah Normal',
                    'body' => 'Fluktuasi suhu pada boiler grup 2 telah disesuaikan oleh teknisi dan beroperasi normal.',
                    'status' => 'sent',
                ],
            ];

            foreach ($sampleNotifs as $sn) {
                $hasNotif = DB::table('WNE.msg_notifications')
                    ->where('recipient_user_id', $adminId)
                    ->where('subject', $sn['subject'])
                    ->exists();

                if (!$hasNotif) {
                    $notifId = DB::table('WNE.msg_notifications')->insertGetId(array_merge($sn, [
                        'data' => json_encode([]),
                        'created_at' => now(),
                    ]));

                    DB::table('WNE.msg_notification_deliveries')->insert([
                        'notification_id' => $notifId,
                        'channel' => 'in_app',
                        'status' => 'delivered',
                        'sent_at' => now(),
                        'delivered_at' => now(),
                    ]);
                }
            }
        }
    }
}
