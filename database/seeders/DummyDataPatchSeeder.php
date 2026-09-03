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
        $tenantId = tenant('id') ?? '001';
        $this->seedTenantData($tenantId);
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

        // Seed stock balance
        $allProducts = DB::table('INVENTORY.products')->get();
        foreach ($allProducts as $prod) {
            DB::table('INVENTORY.stock_balances')->updateOrInsert(
                [
                    'product_id' => $prod->id,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                ],
                [
                    'qty_on_hand' => 100.0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
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
        // 1. Terminals
        $terminal = DB::table('POS.pos_terminals')->where('code', 'POS-01')->first();
        $terminalId = $terminal?->id;
        if (!$terminal) {
            $profile = DB::table('POS.pos_profiles')->first();
            $warehouse = DB::table('INVENTORY.warehouses')->first();
            $terminalId = DB::table('POS.pos_terminals')->insertGetId([
                'profile_id' => $profile?->id ?? 1,
                'warehouse_id' => $warehouse?->id ?? 1,
                'code' => 'POS-01',
                'name' => 'Kasir Utama Depan',
                'receipt_prefix' => 'POS01',
                'last_local_seq' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Secondary Terminal
        $terminal2 = DB::table('POS.pos_terminals')->where('code', 'POS-02')->first();
        if (!$terminal2) {
            $profile = DB::table('POS.pos_profiles')->first();
            $warehouse = DB::table('INVENTORY.warehouses')->first();
            DB::table('POS.pos_terminals')->insert([
                'profile_id' => $profile?->id ?? 1,
                'warehouse_id' => $warehouse?->id ?? 1,
                'code' => 'POS-02',
                'name' => 'Kasir Bar & Minuman',
                'receipt_prefix' => 'POS02',
                'last_local_seq' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
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

        // 4. Favorite Items for POS-01
        $favSkus = ['ESP-001', 'CAP-001', 'TEA-001', 'WAT-001', 'NAS-001', 'MIE-001', 'CRO-001', 'SND-001'];
        $order = 1;
        foreach ($favSkus as $sku) {
            $p = DB::table('INVENTORY.products')->where('sku', $sku)->first();
            if ($p && $terminalId) {
                DB::table('POS.pos_favorite_items')->updateOrInsert(
                    [
                        'terminal_id' => $terminalId,
                        'product_id' => $p->id,
                    ],
                    [
                        'sort_order' => $order++,
                    ]
                );
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
}
