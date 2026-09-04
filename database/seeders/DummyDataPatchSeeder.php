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
        $this->seedSales();
        $this->seedPurchase();
        $this->seedPosData();
        $this->seedAccounting();
        $this->seedCrm();
        $this->seedHcm();
        $this->seedLegal();
        $this->seedDms();
        $this->seedSchedule();
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
            ['code' => 'MTR', 'name' => 'Meter'],
            ['code' => 'DZN', 'name' => 'Lusin (12 Pcs)'],
        ];

        $uomMap = [];
        foreach ($uoms as $u) {
            $existing = DB::table('INVENTORY.uoms')->where('code', $u['code'])->first();
            if (!$existing) {
                $id = DB::table('INVENTORY.uoms')->insertGetId([
                    'code' => $u['code'],
                    'name' => $u['name'],
                    'is_active' => true,
                ]);
                $uomMap[$u['code']] = $id;
            } else {
                $uomMap[$u['code']] = $existing->id;
            }
        }

        // 2. Categories
        $categories = [
            'Minuman & Kopi',
            'Makanan & Hidangan Utama',
            'Roti & Pastry',
            'Alat Tulis Kantor (Stationery)',
            'Perangkat Keras & Elektronik',
            'Bahan Baku & Mentah',
        ];

        $catMap = [];
        foreach ($categories as $cat) {
            $existing = DB::table('INVENTORY.product_categories')->where('name', $cat)->first();
            if (!$existing) {
                $id = DB::table('INVENTORY.product_categories')->insertGetId([
                    'name' => $cat,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $catMap[$cat] = $id;
            } else {
                $catMap[$cat] = $existing->id;
            }
        }

        // 3. Products
        $catBeverage = $catMap['Minuman & Kopi'] ?? 1;
        $catFood = $catMap['Makanan & Hidangan Utama'] ?? 1;
        $catStationery = $catMap['Alat Tulis Kantor (Stationery)'] ?? 1;
        $catHardware = $catMap['Perangkat Keras & Elektronik'] ?? 1;
        $catRaw = $catMap['Bahan Baku & Mentah'] ?? 1;

        $uomPcs = $uomMap['PCS'] ?? 1;
        $uomCup = $uomMap['CUP'] ?? $uomPcs;
        $uomPorsi = $uomMap['PORSI'] ?? $uomPcs;
        $uomBtl = $uomMap['BTL'] ?? $uomPcs;
        $uomRim = $uomMap['RIM'] ?? $uomPcs;
        $uomKg = $uomMap['KG'] ?? $uomPcs;
        $uomL = $uomMap['L'] ?? $uomPcs;

        $products = [
            [
                'sku' => 'ESP-001',
                'name' => 'Espresso Single Origin',
                'description' => 'Kopi espresso pekat beraroma khas dari biji kopi pilihan nusantara.',
                'category_id' => $catBeverage,
                'base_uom_id' => $uomCup,
                'costing_method' => 'fifo',
                'reorder_point' => 20,
                'reorder_quantity' => 50,
                'image_url' => '/images/products/espresso.svg',
                'barcode' => '8991001001',
            ],
            [
                'sku' => 'CAP-001',
                'name' => 'Cappuccino Latte Art',
                'description' => 'Espresso dengan paduan susu berbusa lembut dan seni latte art indah.',
                'category_id' => $catBeverage,
                'base_uom_id' => $uomCup,
                'costing_method' => 'fifo',
                'reorder_point' => 20,
                'reorder_quantity' => 50,
                'image_url' => '/images/products/cappuccino.svg',
                'barcode' => '8991001002',
            ],
            [
                'sku' => 'TEA-001',
                'name' => 'Es Teh Manis Jasmine',
                'description' => 'Teh melati wangi menyegarkan disajikan dingin dengan gula tebu murni.',
                'category_id' => $catBeverage,
                'base_uom_id' => $uomCup,
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
                'name' => 'Stapler Heavy Duty & Isi No. 3',
                'description' => 'Alat hekter jilid dokumen tebal hingga 100 lembar besi kokoh.',
                'category_id' => $catStationery,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 5,
                'reorder_quantity' => 15,
                'image_url' => '/images/products/stapler.svg',
                'barcode' => '8991001011',
            ],
            [
                'sku' => 'SCN-001',
                'name' => 'Barcode Scanner 2D QR Bluetooth',
                'description' => 'Pemindai barcode laser nirkabel kecepatan tinggi untuk kasir POS.',
                'category_id' => $catHardware,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 5,
                'reorder_quantity' => 10,
                'image_url' => '/images/products/barcode-scanner.svg',
                'barcode' => '8991001012',
            ],
            [
                'sku' => 'RAW-COF-01',
                'name' => 'Biji Kopi Hijau Arabika Gayo',
                'description' => 'Biji kopi mentah pilihan grade 1 single origin Aceh Gayo.',
                'category_id' => $catRaw,
                'base_uom_id' => $uomKg,
                'costing_method' => 'fifo',
                'reorder_point' => 50,
                'reorder_quantity' => 100,
                'image_url' => '/images/products/mineral-water.svg',
                'barcode' => '8991002001',
            ],
            [
                'sku' => 'RAW-MLK-01',
                'name' => 'Susu Segar Murni Pasteurised',
                'description' => 'Susu sapi segar pasteurisasi dingin untuk olahan kopi barista.',
                'category_id' => $catRaw,
                'base_uom_id' => $uomL,
                'costing_method' => 'fifo',
                'reorder_point' => 30,
                'reorder_quantity' => 60,
                'image_url' => '/images/products/mineral-water.svg',
                'barcode' => '8991002002',
            ],
            [
                'sku' => 'RAW-CUP-01',
                'name' => 'Paper Cup 12oz Eco-Friendly',
                'description' => 'Gelas kertas ramah lingkungan tahan panas untuk sajian kopi.',
                'category_id' => $catRaw,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 200,
                'reorder_quantity' => 500,
                'image_url' => '/images/products/mineral-water.svg',
                'barcode' => '8991002003',
            ],
            [
                'sku' => 'RAW-RIC-01',
                'name' => 'Beras Pandan Wangi Premium',
                'description' => 'Beras pulen wangi kualitas super untuk hidangan nasi resto.',
                'category_id' => $catRaw,
                'base_uom_id' => $uomKg,
                'costing_method' => 'fifo',
                'reorder_point' => 100,
                'reorder_quantity' => 250,
                'image_url' => '/images/products/mineral-water.svg',
                'barcode' => '8991002004',
            ],
            [
                'sku' => 'PKG-BOX-01',
                'name' => 'Food Grade Takeaway Paper Box',
                'description' => 'Kotak makanan higienis anti-minyak untuk pesanan takeaway.',
                'category_id' => $catRaw,
                'base_uom_id' => $uomPcs,
                'costing_method' => 'fifo',
                'reorder_point' => 150,
                'reorder_quantity' => 400,
                'image_url' => '/images/products/mineral-water.svg',
                'barcode' => '8991002005',
            ],
        ];

        foreach ($products as $p) {
            $existingProd = DB::table('INVENTORY.products')->where('sku', $p['sku'])->first();
            if (!$existingProd) {
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
                    'is_active' => true,
                    'image_url' => $p['image_url'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $productId = $existingProd->id;
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

        // 4. Warehouses (5 Warehouses)
        $warehouses = [
            ['name' => 'Toko Utama', 'address' => 'Jl. Pemuda No. 123, Surabaya'],
            ['name' => 'Resto Warehouse', 'address' => 'Mall Grand City Lt. 2, Surabaya'],
            ['name' => 'Gudang Logistik Pusat', 'address' => 'Kawasan Industri Rungkut Blok A-5, Surabaya'],
            ['name' => 'Gudang Roastery & Bahan Mentah', 'address' => 'Jl. Mayjend Sungkono No. 45, Surabaya'],
            ['name' => 'Outlet Cabang Barat', 'address' => 'Jl. HR Muhammad No. 88, Surabaya'],
        ];

        foreach ($warehouses as $wh) {
            $existingWh = DB::table('INVENTORY.warehouses')->where('name', $wh['name'])->first();
            if (!$existingWh) {
                $whId = DB::table('INVENTORY.warehouses')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'name' => $wh['name'],
                    'address' => $wh['address'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $whId = $existingWh->id;
            }

            $loc = DB::table('INVENTORY.locations')->where('warehouse_id', $whId)->first();
            if (!$loc) {
                DB::table('INVENTORY.locations')->insert([
                    'warehouse_id' => $whId,
                    'code' => 'LOC-' . $whId . '-FRONT',
                    'type' => 'bin',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
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

    private function seedSales(): void
    {
        $adminUser = DB::table('users')->where('email', 'admin@nusaevo.com')->first() ?? DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;

        // 1. Price Lists (5 Price Lists)
        $priceLists = [
            ['name' => 'Standard Commercial Rates 2026', 'default' => true, 'factor' => 1.0],
            ['name' => 'Wholesale B2B Distributor Rates', 'default' => false, 'factor' => 0.85],
            ['name' => 'VIP Member Loyalty Price List', 'default' => false, 'factor' => 0.90],
            ['name' => 'Weekend Promo Special Rates', 'default' => false, 'factor' => 0.95],
            ['name' => 'Corporate Employee Pricing', 'default' => false, 'factor' => 0.80],
        ];

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
            'RAW-COF-01' => 85000,
            'RAW-MLK-01' => 22000,
            'RAW-CUP-01' => 1200,
            'RAW-RIC-01' => 18000,
            'PKG-BOX-01' => 2500,
        ];

        $defaultPriceListId = null;
        foreach ($priceLists as $pl) {
            $existing = DB::table('SALES.price_lists')->where('name', $pl['name'])->first();
            if (!$existing) {
                $pId = DB::table('SALES.price_lists')->insertGetId([
                    'name' => $pl['name'],
                    'currency' => 'IDR',
                    'is_tenant_default' => $pl['default'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $pId = $existing->id;
                DB::table('SALES.price_lists')->where('id', $pId)->update(['is_tenant_default' => $pl['default']]);
            }

            if ($pl['default']) {
                $defaultPriceListId = $pId;
            }

            foreach ($priceMap as $sku => $basePrice) {
                $prod = DB::table('INVENTORY.products')->where('sku', $sku)->first();
                if ($prod) {
                    DB::table('SALES.price_list_lines')->updateOrInsert(
                        [
                            'price_list_id' => $pId,
                            'product_id' => $prod->id,
                        ],
                        [
                            'item_type' => 'product',
                            'description' => $prod->name,
                            'unit_price' => round($basePrice * $pl['factor']),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }
        }

        // 2. Quotations (5 Quotations)
        $partners = DB::table('CRM.partners')->take(5)->get();
        if ($partners->count() >= 5 && $defaultPriceListId) {
            for ($i = 1; $i <= 5; $i++) {
                $partner = $partners[$i - 1];
                $quoteGroup = (string) Str::uuid();
                $existingQ = DB::table('SALES.quot_hdrs')->where('customer_id', $partner->id)->first();
                if (!$existingQ) {
                    $qId = DB::table('SALES.quot_hdrs')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'quote_group_id' => $quoteGroup,
                        'revision_no' => 1,
                        'customer_id' => $partner->id,
                        'price_list_id' => $defaultPriceListId,
                        'validity_date' => now()->addDays(30)->toDateString(),
                        'status' => $i % 2 == 0 ? 'accepted' : 'draft',
                        'created_by' => $adminId,
                        'created_at' => now()->subDays(10 - $i),
                        'updated_at' => now()->subDays(10 - $i),
                    ]);

                    DB::table('SALES.quot_lines')->insert([
                        'quot_hdr_id' => $qId,
                        'line_no' => 1,
                        'item_type' => 'product',
                        'description' => 'Paket Pengadaan Biji Kopi & Konsumsi Kantor',
                        'quantity' => 50,
                        'unit_price' => 25000,
                        'discount_amount' => 0,
                        'tax_amount' => 137500,
                        'line_total' => 1250000,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // 3. Sales Orders (5 SO)
            for ($i = 1; $i <= 5; $i++) {
                $soNum = sprintf('SO-2026-%03d', $i);
                $existingSo = DB::table('SALES.so_hdrs')->where('so_number', $soNum)->first();
                if (!$existingSo) {
                    $soId = DB::table('SALES.so_hdrs')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'so_number' => $soNum,
                        'customer_id' => $partners[$i - 1]->id,
                        'price_list_id' => $defaultPriceListId,
                        'status' => $i <= 3 ? 'confirmed' : 'in_fulfillment',
                        'created_by' => $adminId,
                        'created_at' => now()->subDays(7 - $i),
                        'updated_at' => now()->subDays(7 - $i),
                    ]);

                    $soLineId = DB::table('SALES.so_lines')->insertGetId([
                        'so_hdr_id' => $soId,
                        'line_no' => 1,
                        'item_type' => 'product',
                        'description' => 'Pesanan Penjualan Grosir Cabang ' . $i,
                        'qty_ordered' => 100,
                        'qty_delivered' => 100,
                        'qty_invoiced' => 0,
                        'unit_price' => 22000,
                        'discount_amount' => 0,
                        'tax_amount' => 242000,
                        'line_total' => 2200000,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // 4. Deliveries (5 DO)
                    $dlvId = DB::table('SALES.dlv_hdrs')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'so_hdr_id' => $soId,
                        'status' => 'delivered',
                        'carrier' => 'Kurir Internal Logistik',
                        'tracking_number' => 'TRK-2026-' . $i,
                        'shipped_at' => now()->subDays(2),
                        'delivered_at' => now()->subDay(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('SALES.dlv_lines')->insert([
                        'dlv_hdr_id' => $dlvId,
                        'so_line_id' => $soLineId,
                        'qty_shipped' => 100,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function seedPurchase(): void
    {
        $adminUser = DB::table('users')->where('email', 'admin@nusaevo.com')->first() ?? DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;
        $wh = DB::table('INVENTORY.warehouses')->first();
        $whId = $wh?->id ?? 1;

        $vendors = DB::table('CRM.partners')->take(5)->get();
        if ($vendors->count() >= 5) {
            for ($i = 1; $i <= 5; $i++) {
                $vendor = $vendors[$i - 1];

                // Ensure vendor profile
                DB::table('PURCHASE.vendor_profiles')->updateOrInsert(
                    ['partner_id' => $vendor->id],
                    [
                        'payment_terms_days' => 30,
                        'is_preferred' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                // 1. Purchase Requisitions (5 PR)
                $prNo = sprintf('PR-2026-%04d', $i);
                $existingPr = DB::table('PURCHASE.pur_requisition_hdrs')->where('pr_no', $prNo)->first();
                if (!$existingPr) {
                    $prId = DB::table('PURCHASE.pur_requisition_hdrs')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'pr_no' => $prNo,
                        'requester_id' => $adminId,
                        'needed_by' => now()->addDays(14)->toDateString(),
                        'status' => 'approved',
                        'estimated_total' => 15000000 + ($i * 2000000),
                        'budget_warning' => false,
                        'duplicate_warning' => false,
                        'notes' => 'Pengajuan pengadaan bahan operasional bulan berjalan batch ' . $i,
                        'created_by' => $adminId,
                        'created_at' => now()->subDays(15 - $i),
                        'updated_at' => now()->subDays(15 - $i),
                    ]);

                    DB::table('PURCHASE.pur_requisition_lines')->insert([
                        'pr_id' => $prId,
                        'line_no' => 1,
                        'description' => 'Bahan Baku Mentah Batch ' . $i,
                        'qty' => 200,
                        'estimated_unit_price' => 75000,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $prId = $existingPr->id;
                }

                // 2. Purchase Orders (5 PO)
                $poNo = sprintf('PO-2026-%04d', $i);
                $existingPo = DB::table('PURCHASE.pur_order_hdrs')->where('po_no', $poNo)->first();
                if (!$existingPo) {
                    $poId = DB::table('PURCHASE.pur_order_hdrs')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'po_no' => $poNo,
                        'supplier_id' => $vendor->id,
                        'pr_id' => $prId,
                        'currency_code' => 'IDR',
                        'payment_terms_days' => 30,
                        'status' => 'received',
                        'revision_no' => 1,
                        'subtotal' => 15000000,
                        'tax_amount' => 1650000,
                        'total_amount' => 16650000,
                        'expected_delivery_date' => now()->addDays(7)->toDateString(),
                        'created_by' => $adminId,
                        'created_at' => now()->subDays(12 - $i),
                        'updated_at' => now()->subDays(12 - $i),
                    ]);

                    $poLineId = DB::table('PURCHASE.pur_order_lines')->insertGetId([
                        'po_id' => $poId,
                        'line_no' => 1,
                        'description' => 'Pengadaan Bahan Suplai ' . $i,
                        'qty_ordered' => 200,
                        'qty_received' => 200,
                        'unit_price' => 75000,
                        'tax_amount' => 1650000,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // 3. Goods Receipts (5 GR)
                    $grNo = sprintf('GR-2026-%04d', $i);
                    $grId = DB::table('PURCHASE.pur_receipt_hdrs')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'gr_no' => $grNo,
                        'po_id' => $poId,
                        'receiver_id' => $adminId,
                        'received_at' => now()->subDays(5 - $i),
                        'warehouse_id' => $whId,
                        'status' => 'completed',
                        'discrepancy_notes' => 'Penerimaan barang lengkap sesuai surat jalan.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('PURCHASE.pur_receipt_lines')->insert([
                        'gr_id' => $grId,
                        'po_line_id' => $poLineId,
                        'quantity_received' => 200,
                        'unit_cost' => 75000,
                        'over_receipt_flag' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // 4. Vendor Invoices (5 Invoices)
                    $invId = DB::table('PURCHASE.pur_invoice_hdrs')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'po_id' => $poId,
                        'supplier_id' => $vendor->id,
                        'supplier_invoice_no' => 'VINV-2026-00' . $i,
                        'supplier_invoice_date' => now()->subDays(4)->toDateString(),
                        'currency_code' => 'IDR',
                        'amount' => 16650000,
                        'submission_channel' => 'portal',
                        'match_status' => 'matched',
                        'status' => 'approved',
                        'created_by' => $adminId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('PURCHASE.pur_invoice_lines')->insert([
                        'invoice_id' => $invId,
                        'po_line_id' => $poLineId,
                        'qty' => 200,
                        'unit_price' => 75000,
                        'line_amount' => 15000000,
                    ]);
                }
            }
        }
    }

    private function seedPosData(): void
    {
        $priceList = DB::table('SALES.price_lists')->where('is_tenant_default', true)->first();
        $priceListId = $priceList?->id;

        $restaurantProfile = DB::table('POS.pos_profiles')->where('code', 'RESTAURANT')->first();
        $warehouse = DB::table('INVENTORY.warehouses')->first();
        $whId = $warehouse?->id ?? 1;

        // 1. Terminals (5 Terminals)
        $terminals = [
            ['code' => 'POS-01', 'name' => 'Kasir Utama Depan', 'prefix' => 'POS01'],
            ['code' => 'POS-02', 'name' => 'Kasir Bar & Minuman', 'prefix' => 'POS02'],
            ['code' => 'POS-03', 'name' => 'Kasir Drive-Thru Cepat', 'prefix' => 'POS03'],
            ['code' => 'POS-04', 'name' => 'Kasir Takeaway & Ojol', 'prefix' => 'POS04'],
            ['code' => 'POS-05', 'name' => 'Mobile Tablet Waiter', 'prefix' => 'POS05'],
        ];

        foreach ($terminals as $term) {
            $existing = DB::table('POS.pos_terminals')->where('code', $term['code'])->first();
            if (!$existing) {
                DB::table('POS.pos_terminals')->insert([
                    'profile_id' => $restaurantProfile?->id ?? 1,
                    'warehouse_id' => $whId,
                    'code' => $term['code'],
                    'name' => $term['name'],
                    'receipt_prefix' => $term['prefix'],
                    'default_price_list_id' => $priceListId,
                    'last_local_seq' => 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('POS.pos_terminals')->where('id', $existing->id)->update([
                    'default_price_list_id' => $priceListId,
                    'warehouse_id' => $whId,
                    'profile_id' => $restaurantProfile?->id ?? $existing->profile_id,
                ]);
            }
        }

        // 2. Floors & Tables (5 Floors)
        $floors = [
            ['name' => 'Lantai 1 (Main Dining)', 'ref' => 'L1-MAIN'],
            ['name' => 'Lantai 2 (Outdoor Rooftop)', 'ref' => 'L2-ROOF'],
            ['name' => 'Lantai 3 (Rooftop Lounge & Bar)', 'ref' => 'L3-LOUNGE'],
            ['name' => 'Area Outdoor Garden Terrace', 'ref' => 'OUT-GARDEN'],
            ['name' => 'VIP Private Dining Room', 'ref' => 'VIP-ROOM'],
        ];

        $floorIds = [];
        foreach ($floors as $fl) {
            $existing = DB::table('POS.pos_floors')->where('name', $fl['name'])->first();
            if (!$existing) {
                $fId = DB::table('POS.pos_floors')->insertGetId([
                    'name' => $fl['name'],
                    'layout_ref' => $fl['ref'],
                ]);
                $floorIds[$fl['name']] = $fId;
            } else {
                $floorIds[$fl['name']] = $existing->id;
            }
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
                ['floor_id' => $floorIds['Lantai 1 (Main Dining)'], 'code' => $t['code']],
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
                ['floor_id' => $floorIds['Lantai 2 (Outdoor Rooftop)'], 'code' => $t['code']],
                ['seat_count' => $t['seat_count'], 'pos_x' => $t['pos_x'], 'pos_y' => $t['pos_y'], 'status' => $t['status']]
            );
        }

        // Tables for Floor 3, 4, 5
        DB::table('POS.pos_tables')->updateOrInsert(
            ['floor_id' => $floorIds['Lantai 3 (Rooftop Lounge & Bar)'], 'code' => 'L3-01'],
            ['seat_count' => 4, 'pos_x' => 20, 'pos_y' => 20, 'status' => 'available']
        );
        DB::table('POS.pos_tables')->updateOrInsert(
            ['floor_id' => $floorIds['Area Outdoor Garden Terrace'], 'code' => 'G-01'],
            ['seat_count' => 4, 'pos_x' => 20, 'pos_y' => 20, 'status' => 'available']
        );
        DB::table('POS.pos_tables')->updateOrInsert(
            ['floor_id' => $floorIds['VIP Private Dining Room'], 'code' => 'VIP-01'],
            ['seat_count' => 10, 'pos_x' => 50, 'pos_y' => 50, 'status' => 'available']
        );

        // 3. KDS Stations (5 Stations)
        $stations = [
            ['code' => 'BAR', 'name' => 'Bar & Coffee Station'],
            ['code' => 'KITCHEN', 'name' => 'Main Hot Kitchen'],
            ['code' => 'PASTRY', 'name' => 'Pastry & Dessert Station'],
            ['code' => 'GRILL', 'name' => 'Grill & BBQ Station'],
            ['code' => 'PACK', 'name' => 'Station Packing & Dispatch'],
        ];
        $stnIds = [];
        foreach ($stations as $stn) {
            $existing = DB::table('POS.pos_kds_stations')->where('code', $stn['code'])->first();
            if (!$existing) {
                $sId = DB::table('POS.pos_kds_stations')->insertGetId([
                    'code' => $stn['code'],
                    'name' => $stn['name'],
                ]);
                $stnIds[$stn['code']] = $sId;
            } else {
                $stnIds[$stn['code']] = $existing->id;
            }
        }

        // 4. Modifier Groups (5 Groups)
        $modGroups = [
            ['name' => 'Pilihan Topping Tambahan', 'type' => 'multiple', 'min' => 0, 'max' => 3],
            ['name' => 'Tingkat Kepedasan', 'type' => 'single', 'min' => 1, 'max' => 1],
            ['name' => 'Ukuran Cup / Size', 'type' => 'single', 'min' => 1, 'max' => 1],
            ['name' => 'Pilihan Susu / Milk Choice', 'type' => 'single', 'min' => 0, 'max' => 1],
            ['name' => 'Tingkat Kemanisan / Sugar Level', 'type' => 'single', 'min' => 1, 'max' => 1],
        ];

        $mgIds = [];
        foreach ($modGroups as $mg) {
            $existing = DB::table('POS.pos_modifier_groups')->where('name', $mg['name'])->first();
            if (!$existing) {
                $mId = DB::table('POS.pos_modifier_groups')->insertGetId([
                    'name' => $mg['name'],
                    'selection_type' => $mg['type'],
                    'min_selections' => $mg['min'],
                    'max_selections' => $mg['max'],
                ]);
                $mgIds[$mg['name']] = $mId;
            } else {
                $mgIds[$mg['name']] = $existing->id;
            }
        }

        // Modifiers items
        $modifiersData = [
            $mgIds['Pilihan Topping Tambahan'] => [
                ['name' => 'Extra Espresso Shot', 'price_delta' => 6000],
                ['name' => 'Boba Brown Sugar', 'price_delta' => 5000],
                ['name' => 'Coffee Jelly', 'price_delta' => 4000],
            ],
            $mgIds['Tingkat Kepedasan'] => [
                ['name' => 'Tidak Pedas', 'price_delta' => 0],
                ['name' => 'Sedang (Level 1)', 'price_delta' => 0],
                ['name' => 'Pedas Mantap (Level 3)', 'price_delta' => 3000],
            ],
            $mgIds['Ukuran Cup / Size'] => [
                ['name' => 'Regular (12oz)', 'price_delta' => 0],
                ['name' => 'Large (16oz)', 'price_delta' => 5000],
            ],
            $mgIds['Pilihan Susu / Milk Choice'] => [
                ['name' => 'Fresh Milk Sapi', 'price_delta' => 0],
                ['name' => 'Oat Milk Barista', 'price_delta' => 7000],
                ['name' => 'Almond Milk', 'price_delta' => 8000],
            ],
            $mgIds['Tingkat Kemanisan / Sugar Level'] => [
                ['name' => 'Normal Sugar (100%)', 'price_delta' => 0],
                ['name' => 'Less Sugar (50%)', 'price_delta' => 0],
                ['name' => 'No Sugar (0%)', 'price_delta' => 0],
            ],
        ];

        foreach ($modifiersData as $groupId => $items) {
            if ($groupId) {
                foreach ($items as $m) {
                    DB::table('POS.pos_modifiers')->updateOrInsert(
                        ['group_id' => $groupId, 'name' => $m['name']],
                        ['price_delta' => $m['price_delta'], 'replaces_base_price' => false]
                    );
                }
            }
        }

        // 5. POS Sessions (5 Sessions) & Transactions (5 Transactions)
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;
        $term1 = DB::table('POS.pos_terminals')->first();
        $termId = $term1?->id ?? 1;

        for ($i = 1; $i <= 5; $i++) {
            $sess = DB::table('POS.pos_sessions')->where('id', $i)->first();
            if (!$sess) {
                DB::table('POS.pos_sessions')->insert([
                    'id' => $i,
                    'terminal_id' => $termId,
                    'cashier_user_id' => $adminId,
                    'opened_at' => now()->subDays(6 - $i)->setTime(8, 0),
                    'opening_cash' => 500000.0,
                    'status' => 'closed',
                    'closed_at' => now()->subDays(6 - $i)->setTime(22, 0),
                    'expected_cash' => 2500000.0,
                    'actual_cash' => 2500000.0,
                    'variance' => 0.0,
                    'closed_by' => $adminId,
                ]);
            }

            $txnNum = sprintf('TXN-2026-090%d', $i);
            $existingTxn = DB::table('POS.pos_txn_hdrs')->where('receipt_number', $txnNum)->first();
            if (!$existingTxn) {
                $tId = DB::table('POS.pos_txn_hdrs')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'client_txn_uuid' => (string) Str::uuid(),
                    'session_id' => $i,
                    'terminal_id' => $termId,
                    'receipt_number' => $txnNum,
                    'dining_mode' => 'dine_in',
                    'status' => 'completed',
                    'subtotal' => 60000.0,
                    'discount_total' => 0.0,
                    'tax_total' => 6600.0,
                    'service_charge' => 0.0,
                    'rounding' => 0.0,
                    'grand_total' => 66600.0,
                    'is_on_account' => false,
                    'created_offline' => false,
                    'occurred_at' => now()->subDays(6 - $i)->setTime(12, 30),
                    'created_at' => now()->subDays(6 - $i),
                    'updated_at' => now()->subDays(6 - $i),
                ]);

                DB::table('POS.pos_txn_lines')->insert([
                    'txn_id' => $tId,
                    'line_no' => 1,
                    'product_id' => 1,
                    'is_open_item' => false,
                    'description' => 'Espresso Single Origin',
                    'qty' => 2,
                    'unit_price' => 22000.0,
                    'discount_amount' => 0.0,
                    'tax_amount' => 4840.0,
                    'line_total' => 48840.0,
                    'inventory_posted' => true,
                ]);
            }
        }
    }

    private function seedAccounting(): void
    {
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

        // 1. Fiscal Year & 12 Fiscal Periods
        $fy = DB::table('ACCOUNTING.fiscal_years')->where('company_id', $companyId)->where('year', 2026)->first();
        if (!$fy) {
            $fyId = DB::table('ACCOUNTING.fiscal_years')->insertGetId([
                'company_id' => $companyId,
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'open',
            ]);
        } else {
            $fyId = $fy->id;
        }

        for ($p = 1; $p <= 12; $p++) {
            $existingP = DB::table('ACCOUNTING.fiscal_periods')
                ->where('company_id', $companyId)
                ->where('fiscal_year_id', $fyId)
                ->where('period_no', $p)
                ->first();
            if (!$existingP) {
                DB::table('ACCOUNTING.fiscal_periods')->insert([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fyId,
                    'period_no' => $p,
                    'start_date' => sprintf('2026-%02d-01', $p),
                    'end_date' => date('Y-m-t', strtotime(sprintf('2026-%02d-01', $p))),
                    'status' => $p <= 9 ? 'open' : 'future',
                ]);
            }
        }

        // 2. Currencies
        DB::table('ACCOUNTING.currencies')->updateOrInsert(
            ['code' => 'IDR'],
            ['name' => 'Indonesian Rupiah', 'is_enabled' => true]
        );
        DB::table('ACCOUNTING.currencies')->updateOrInsert(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'is_enabled' => true]
        );

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

        // Set control accounts
        $arAcc = DB::table('ACCOUNTING.accounts')->where('company_id', $companyId)->where('account_code', '1104')->value('id');
        $apAcc = DB::table('ACCOUNTING.accounts')->where('company_id', $companyId)->where('account_code', '2101')->value('id');
        $invAcc = DB::table('ACCOUNTING.accounts')->where('company_id', $companyId)->where('account_code', '1105')->value('id');
        DB::table('ACCOUNTING.companies')->where('id', $companyId)->update([
            'ar_control_account_id' => $arAcc,
            'ap_control_account_id' => $apAcc,
            'inventory_control_account_id' => $invAcc,
        ]);

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

        // 4. AR Invoices (5 Invoices) & AP Bills (5 Bills)
        $partners = DB::table('CRM.partners')->take(5)->get();
        $revAcc = DB::table('ACCOUNTING.accounts')->where('company_id', $companyId)->where('account_code', '4102')->value('id');
        $expAcc = DB::table('ACCOUNTING.accounts')->where('company_id', $companyId)->where('account_code', '5101')->value('id');
        if ($partners->count() >= 5) {
            for ($i = 1; $i <= 5; $i++) {
                $p = $partners[$i - 1];
                $invNo = sprintf('INV-AR-2026-%03d', $i);
                $existingInv = DB::table('ACCOUNTING.ar_invoices')->where('invoice_no', $invNo)->first();
                if (!$existingInv) {
                    $arId = DB::table('ACCOUNTING.ar_invoices')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'partner_id' => $p->id,
                        'invoice_no' => $invNo,
                        'invoice_type' => 'standard',
                        'currency_code' => 'IDR',
                        'issue_date' => now()->subDays(10 - $i)->toDateString(),
                        'due_date' => now()->addDays(20 + $i)->toDateString(),
                        'status' => 'posted',
                        'subtotal' => 10000000.0,
                        'tax_amount' => 1100000.0,
                        'total_amount' => 11100000.0,
                        'paid_amount' => 0.0,
                        'credited_amount' => 0.0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($revAcc) {
                        DB::table('ACCOUNTING.ar_invoice_lines')->insert([
                            'ar_invoice_id' => $arId,
                            'line_no' => 1,
                            'description' => 'Penjualan Grosir B2B ' . $i,
                            'qty' => 10,
                            'unit_price' => 1000000.0,
                            'discount_amount' => 0.0,
                            'revenue_account_id' => $revAcc,
                            'line_amount' => 10000000.0,
                            'tax_amount' => 1100000.0,
                        ]);
                    }
                }

                $billNo = sprintf('BILL-AP-2026-%03d', $i);
                $existingBill = DB::table('ACCOUNTING.ap_bills')->where('bill_no', $billNo)->first();
                if (!$existingBill) {
                    $apId = DB::table('ACCOUNTING.ap_bills')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'partner_id' => $p->id,
                        'bill_no' => $billNo,
                        'currency_code' => 'IDR',
                        'issue_date' => now()->subDays(12 - $i)->toDateString(),
                        'due_date' => now()->addDays(15 + $i)->toDateString(),
                        'status' => 'posted',
                        'subtotal' => 8000000.0,
                        'tax_amount' => 880000.0,
                        'withheld_amount' => 0.0,
                        'total_amount' => 8880000.0,
                        'paid_amount' => 0.0,
                        'debited_amount' => 0.0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($expAcc) {
                        DB::table('ACCOUNTING.ap_bill_lines')->insert([
                            'ap_bill_id' => $apId,
                            'line_no' => 1,
                            'description' => 'Tagihan Bahan Baku Pemasok ' . $i,
                            'qty' => 8,
                            'unit_price' => 1000000.0,
                            'discount_amount' => 0.0,
                            'expense_account_id' => $expAcc,
                            'line_amount' => 8000000.0,
                            'tax_amount' => 880000.0,
                        ]);
                    }
                }
            }
        }

        // 5. GL Journals (5 Journals)
        $firstPeriod = DB::table('ACCOUNTING.fiscal_periods')->first();
        $fpId = $firstPeriod?->id ?? 1;
        $accKas = DB::table('ACCOUNTING.accounts')->where('account_code', '1101')->value('id');
        $accRev = DB::table('ACCOUNTING.accounts')->where('account_code', '4101')->value('id');

        if ($accKas && $accRev) {
            for ($i = 1; $i <= 5; $i++) {
                $memo = 'Jurnal Penutupan Kasir POS Harian ' . $i;
                $existingJ = DB::table('ACCOUNTING.gl_journals')->where('memo', $memo)->first();
                if (!$existingJ) {
                    $jId = DB::table('ACCOUNTING.gl_journals')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'company_id' => $companyId,
                        'fiscal_period_id' => $fpId,
                        'journal_date' => now()->subDays(6 - $i)->toDateString(),
                        'currency_code' => 'IDR',
                        'memo' => $memo,
                        'source' => 'manual',
                        'status' => 'posted',
                        'posted_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('ACCOUNTING.gl_journal_lines')->insert([
                        [
                            'journal_id' => $jId,
                            'line_no' => 1,
                            'account_id' => $accKas,
                            'debit' => 2500000.0,
                            'credit' => 0.0,
                            'description' => 'Penerimaan Kas Kasir',
                        ],
                        [
                            'journal_id' => $jId,
                            'line_no' => 2,
                            'account_id' => $accRev,
                            'debit' => 0.0,
                            'credit' => 2500000.0,
                            'description' => 'Pendapatan Penjualan Kasir POS',
                        ],
                    ]);
                }
            }
        }
    }

    private function seedCrm(): void
    {
        // 1. Partners with Logos (11 Partners)
        $partners = [
            ['name' => 'PT Bank Central Asia Tbk', 'trade_name' => 'BCA', 'type' => 'customer', 'source' => 'referral', 'logo_url' => '/images/partners/partner-bca.svg'],
            ['name' => 'PT Bank Mandiri (Persero) Tbk', 'trade_name' => 'Bank Mandiri', 'type' => 'customer', 'source' => 'direct', 'logo_url' => '/images/partners/partner-mandiri.svg'],
            ['name' => 'PT Telekomunikasi Selular', 'trade_name' => 'Telkomsel', 'type' => 'customer', 'source' => 'outbound', 'logo_url' => '/images/partners/partner-telkom.svg'],
            ['name' => 'PT Pertamina Retail', 'trade_name' => 'Pertamina Retail', 'type' => 'customer', 'source' => 'referral', 'logo_url' => '/images/partners/partner-pertamina.svg'],
            ['name' => 'PT Astra International Tbk', 'trade_name' => 'Astra', 'type' => 'customer', 'source' => 'direct', 'logo_url' => '/images/partners/partner-astra.svg'],
            ['name' => 'PT Indofood CBP Sukses Makmur Tbk', 'trade_name' => 'Indofood', 'type' => 'vendor', 'source' => 'direct', 'logo_url' => '/images/partners/partner-indofood.svg'],
            ['name' => 'PT Unilever Indonesia Tbk', 'trade_name' => 'Unilever', 'type' => 'vendor', 'source' => 'direct', 'logo_url' => '/images/partners/partner-unilever.svg'],
            ['name' => 'CV Sumber Kopi Mandiri', 'trade_name' => 'Sumber Kopi', 'type' => 'vendor', 'source' => 'referral', 'logo_url' => '/images/partners/partner-sumberkopi.svg'],
            ['name' => 'Koperasi Petani Susu Sejahtera', 'trade_name' => 'KPS Sejahtera', 'type' => 'vendor', 'source' => 'direct', 'logo_url' => '/images/partners/partner-kps.svg'],
            ['name' => 'PT Kemasan Nusantara Indah', 'trade_name' => 'Kemasan Indah', 'type' => 'vendor', 'source' => 'direct', 'logo_url' => '/images/partners/partner-kemasan.svg'],
            ['name' => 'PT Duta Logistik Cepat', 'trade_name' => 'Duta Logistik', 'type' => 'partner', 'source' => 'direct', 'logo_url' => '/images/partners/partner-duta.svg'],
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

        // 2. Leads (5 Leads)
        $leads = [
            ['name' => 'Pengadaan POS & Barcode 15 Outlet Retail', 'company_name' => 'PT Retail Modern Nusantara', 'stage' => 'proposal', 'val' => 125000000],
            ['name' => 'Implementasi ERP Resto & KDS Kitchen', 'company_name' => 'Kopi Kenangan Senja Cafe Group', 'stage' => 'qualified', 'val' => 85000000],
            ['name' => 'Software Payroll BPJS & Pajak PPh 21 Terintegrasi', 'company_name' => 'PT Logistik Cepat Sentosa', 'stage' => 'contacted', 'val' => 60000000],
            ['name' => 'Migrasi Sistem Akuntansi ke Coretax DJP', 'company_name' => 'CV Mega Baja Konstruksi', 'stage' => 'new', 'val' => 45000000],
            ['name' => 'Pemasangan Modul Manufaktur & MRP Produksi', 'company_name' => 'PT Pangan Manufaktur Jaya', 'stage' => 'proposal', 'val' => 150000000],
        ];

        foreach ($leads as $l) {
            DB::table('CRM.leads')->updateOrInsert(
                ['name' => $l['name']],
                [
                    'company_name' => $l['company_name'],
                    'stage' => $l['stage'],
                    'estimated_value' => $l['val'],
                    'notes' => 'Prospek kerjasama ERP enterprise tier.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 3. Service Cases (5 Cases)
        $firstPartner = DB::table('CRM.partners')->first();
        if ($firstPartner) {
            $cases = [
                ['subject' => 'Bantuan Integrasi QRIS Statis di Kasir POS', 'priority' => 'high'],
                ['subject' => 'Permintaan Tambahan Lisensi Terminal Tablet', 'priority' => 'normal'],
                ['subject' => 'Konfigurasi Format Slip Gaji PDF Karyawan', 'priority' => 'normal'],
                ['subject' => 'Penyesuaian Akun Pajak Pertambahan Nilai PPh 23', 'priority' => 'urgent'],
                ['subject' => 'Training Pengoperasian Modul KDS Kitchen', 'priority' => 'low'],
            ];

            foreach ($cases as $c) {
                DB::table('CRM.svc_cases')->updateOrInsert(
                    ['subject' => $c['subject']],
                    [
                        'partner_id' => $firstPartner->id,
                        'priority' => $c['priority'],
                        'status' => 'in_progress',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            // 4. Helpdesk Tickets (5 Tickets)
            $tickets = [
                ['subject' => 'Printer Kasir Thermal Tidak Mencetak Kertas Struk', 'priority' => 'high'],
                ['subject' => 'Scanner Barcode USB Tidak Terdeteksi di PC Kasir', 'priority' => 'normal'],
                ['subject' => 'Lupa Password Akun Admin Gudang Utama', 'priority' => 'urgent'],
                ['subject' => 'Stok Fisik di Toko Beda dengan Dashboard Sistem', 'priority' => 'high'],
                ['subject' => 'Request Fitur Export Laporan Penjualan ke Excel', 'priority' => 'normal'],
            ];

            foreach ($tickets as $t) {
                DB::table('CRM.hd_tickets')->updateOrInsert(
                    ['subject' => $t['subject']],
                    [
                        'partner_id' => $firstPartner->id,
                        'requester_name' => 'Staf Operasional',
                        'priority' => $t['priority'],
                        'status' => 'open',
                        'channel' => 'in_app',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedHcm(): void
    {
        // 1. Org Units (5 Units)
        $units = [
            ['name' => 'Direksi & Manajemen Eksekutif', 'type' => 'division'],
            ['name' => 'Divisi Keuangan, Pajak & Legal', 'type' => 'division'],
            ['name' => 'Divisi Operasional & Manajemen Gudang', 'type' => 'division'],
            ['name' => 'Divisi Penjualan, Retail & POS', 'type' => 'division'],
            ['name' => 'Divisi Teknologi Informasi & Sistem', 'type' => 'division'],
        ];

        $unitIds = [];
        foreach ($units as $u) {
            $existing = DB::table('HCM.org_units')->where('name', $u['name'])->first();
            if (!$existing) {
                $uId = DB::table('HCM.org_units')->insertGetId([
                    'name' => $u['name'],
                    'unit_type' => $u['type'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $unitIds[$u['name']] = $uId;
            } else {
                $unitIds[$u['name']] = $existing->id;
            }
        }

        // 2. Jobs & Positions (5 Positions)
        $jobs = [
            'Direktur Utama',
            'Finance & Tax Manager',
            'Warehouse Supervisor',
            'Head Barista & Resto Lead',
            'Senior Software Engineer',
        ];

        $jobIds = [];
        foreach ($jobs as $j) {
            $existing = DB::table('HCM.jobs')->where('title', $j)->first();
            if (!$existing) {
                $jId = DB::table('HCM.jobs')->insertGetId([
                    'code' => strtoupper(substr(str_replace(' ', '', $j), 0, 6)),
                    'title' => $j,
                    'is_active' => true,
                ]);
                $jobIds[$j] = $jId;
            } else {
                $jobIds[$j] = $existing->id;
            }
        }

        $posMap = [];
        $unitKeys = array_values($unitIds);
        foreach ($jobs as $idx => $j) {
            $existing = DB::table('HCM.positions')->where('job_id', $jobIds[$j])->first();
            if (!$existing) {
                $posId = DB::table('HCM.positions')->insertGetId([
                    'job_id' => $jobIds[$j],
                    'org_unit_id' => $unitKeys[$idx % count($unitKeys)],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $posMap[$j] = $posId;
            } else {
                $posMap[$j] = $existing->id;
            }
        }

        // 3. Shifts (5 Shifts)
        $shifts = [
            ['name' => 'Shift Pagi Resto (07:00 - 15:00)', 'start' => '07:00:00', 'end' => '15:00:00'],
            ['name' => 'Shift Sore Resto (15:00 - 23:00)', 'start' => '15:00:00', 'end' => '23:00:00'],
            ['name' => 'Shift Malam Produksi (23:00 - 07:00)', 'start' => '23:00:00', 'end' => '07:00:00'],
            ['name' => 'Office Hours Headquarter (08:30 - 17:30)', 'start' => '08:30:00', 'end' => '17:30:00'],
            ['name' => 'Weekend Special Shift (09:00 - 18:00)', 'start' => '09:00:00', 'end' => '18:00:00'],
        ];

        foreach ($shifts as $s) {
            DB::table('HCM.shifts')->updateOrInsert(
                ['name' => $s['name']],
                [
                    'start_time' => $s['start'],
                    'end_time' => $s['end'],
                    'break_minutes' => 60,
                    'is_active' => true,
                ]
            );
        }

        // 4. Employees (5 Employees)
        $empList = [
            ['no' => 'EMP-0001', 'name' => 'Siti Nurhaliza, S.E.', 'gender' => 'female', 'pos' => 'Finance & Tax Manager', 'salary' => 15000000],
            ['no' => 'EMP-0002', 'name' => 'Budi Santoso, S.Kom.', 'gender' => 'male', 'pos' => 'Senior Software Engineer', 'salary' => 18000000],
            ['no' => 'EMP-0003', 'name' => 'Dewi Lestari, S.Log.', 'gender' => 'female', 'pos' => 'Warehouse Supervisor', 'salary' => 11000000],
            ['no' => 'EMP-0004', 'name' => 'Ahmad Fauzi', 'gender' => 'male', 'pos' => 'Head Barista & Resto Lead', 'salary' => 9500000],
            ['no' => 'EMP-0005', 'name' => 'Rian Pratama, M.M.', 'gender' => 'male', 'pos' => 'Direktur Utama', 'salary' => 35000000],
        ];

        $avatars = [
            '/images/avatars/avatar-1.svg',
            '/images/avatars/avatar-2.svg',
            '/images/avatars/avatar-3.svg',
            '/images/avatars/avatar-4.svg',
            '/images/avatars/avatar-5.svg',
        ];

        foreach ($empList as $idx => $e) {
            $existing = DB::table('HCM.employees')->where('employee_no', $e['no'])->first();
            if (!$existing) {
                $eId = DB::table('HCM.employees')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'employee_no' => $e['no'],
                    'full_name' => $e['name'],
                    'gender' => $e['gender'],
                    'hire_date' => '2024-01-15',
                    'employment_status' => 'active',
                    'position_id' => $posMap[$e['pos']] ?? null,
                    'dependents_count' => 1,
                    'avatar_url' => $avatars[$idx % count($avatars)],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $eId = $existing->id;
                DB::table('HCM.employees')->where('id', $eId)->update([
                    'full_name' => $e['name'],
                    'position_id' => $posMap[$e['pos']] ?? $existing->position_id,
                    'avatar_url' => $avatars[$idx % count($avatars)],
                    'updated_at' => now(),
                ]);
            }

            // Employment Contract
            DB::table('HCM.employment_contracts')->updateOrInsert(
                ['employee_id' => $eId],
                [
                    'start_date' => '2024-01-15',
                    'contract_type' => 'PKWTT',
                    'base_salary' => $e['salary'],
                    'work_location' => 'Surabaya Headquarter',
                    'status' => 'active',
                ]
            );
        }

        // 5. Payroll Runs (5 Monthly Runs)
        for ($m = 1; $m <= 5; $m++) {
            $runNum = sprintf('PAY-2026-%02d', $m);
            $existingRun = DB::table('PAYROLL.payroll_runs')->where('run_number', $runNum)->first();
            if (!$existingRun) {
                DB::table('PAYROLL.payroll_runs')->insert([
                    'uuid' => (string) Str::uuid(),
                    'run_number' => $runNum,
                    'period_start' => sprintf('2026-%02d-01', $m),
                    'period_end' => date('Y-m-t', strtotime(sprintf('2026-%02d-01', $m))),
                    'pay_date' => sprintf('2026-%02d-28', $m),
                    'run_type' => 'regular',
                    'status' => 'paid',
                    'total_gross' => 88500000.0,
                    'total_deductions' => 7500000.0,
                    'total_net' => 81000000.0,
                    'total_tax_pph21' => 3500000.0,
                    'is_locked' => true,
                    'locked_at' => now(),
                    'approved_at' => now(),
                    'paid_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedLegal(): void
    {
        $partner = DB::table('CRM.partners')->first();
        $partnerId = $partner?->id;

        $matters = [
            ['code' => 'MAT-2026-001', 'title' => 'Perjanjian Kerjasama Distribusi B2B Nasional', 'type' => 'corporate', 'status' => 'active'],
            ['code' => 'MAT-2026-002', 'title' => 'Pendaftaran Hak Cipta & Merek Dagang NusaEvo ERP', 'type' => 'ipr', 'status' => 'active'],
            ['code' => 'MAT-2026-003', 'title' => 'Perjanjian Sewa Lahan Outlet Resto Grand City', 'type' => 'real_estate', 'status' => 'closed'],
            ['code' => 'MAT-2026-004', 'title' => 'Audit Kepatuhan Perlindungan Data Pribadi (UU PDP)', 'type' => 'compliance', 'status' => 'active'],
            ['code' => 'MAT-2026-005', 'title' => 'Drafting Kontrak Kerjasama Vendor Suplai Biji Kopi', 'type' => 'commercial', 'status' => 'active'],
        ];

        foreach ($matters as $m) {
            DB::table('LEGAL.matters')->updateOrInsert(
                ['code' => $m['code']],
                [
                    'uuid' => (string) Str::uuid(),
                    'title' => $m['title'],
                    'matter_type' => $m['type'],
                    'status' => $m['status'],
                    'partner_id' => $partnerId,
                    'opened_at' => now()->subMonths(2)->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedDms(): void
    {
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;

        // 1. Doc Types (5 Types)
        $docTypes = [
            ['code' => 'KONTRAK', 'name' => 'Kontrak & Perjanjian Bisnis'],
            ['code' => 'LEGALITAS', 'name' => 'Akta Perusahaan & Legalitas'],
            ['code' => 'PAJAK', 'name' => 'Faktur Pajak & Bukti Potong'],
            ['code' => 'SOP', 'name' => 'Standard Operating Procedure (SOP)'],
            ['code' => 'AUDIT', 'name' => 'Laporan Audit & Evaluasi Finansial'],
        ];

        $dtIds = [];
        foreach ($docTypes as $dt) {
            $existing = DB::table('DMS.doc_types')->where('code', $dt['code'])->first();
            if (!$existing) {
                $dId = DB::table('DMS.doc_types')->insertGetId([
                    'code' => $dt['code'],
                    'name' => $dt['name'],
                    'is_active' => true,
                ]);
                $dtIds[$dt['name']] = $dId;
            } else {
                $dtIds[$dt['name']] = $existing->id;
            }
        }

        // 2. Folders (5 Folders)
        $folders = [
            'Dokumen Legal & Kontrak Korporat',
            'Dokumen Keuangan & Pajak Bulanan',
            'Arsip Logistik & Gudang',
            'Operasional Retail Resto & POS',
            'Dokumen Personalia & HRD',
        ];

        $fldIds = [];
        foreach ($folders as $f) {
            $existing = DB::table('DMS.folders')->where('name', $f)->first();
            if (!$existing) {
                $fId = DB::table('DMS.folders')->insertGetId([
                    'name' => $f,
                    'access_flag' => 'internal',
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $fldIds[$f] = $fId;
            } else {
                $fldIds[$f] = $existing->id;
            }
        }

        // 3. Documents (5 Documents)
        $docs = [
            ['title' => 'Akta Pendirian PT Nusa Digital Solusindo', 'folder' => 'Dokumen Legal & Kontrak Korporat', 'type' => 'Akta Perusahaan & Legalitas'],
            ['title' => 'Surat Pengukuhan Pengusaha Kena Pajak (SPPKP)', 'folder' => 'Dokumen Keuangan & Pajak Bulanan', 'type' => 'Faktur Pajak & Bukti Potong'],
            ['title' => 'SOP Alur Penjualan Kasir POS & Tutup Shift', 'folder' => 'Operasional Retail Resto & POS', 'type' => 'Standard Operating Procedure (SOP)'],
            ['title' => 'SOP Prosedur Penerimaan Barang Gudang Utama', 'folder' => 'Arsip Logistik & Gudang', 'type' => 'Standard Operating Procedure (SOP)'],
            ['title' => 'Buku Panduan Karyawan & Kebijakan HR 2026', 'folder' => 'Dokumen Personalia & HRD', 'type' => 'Standard Operating Procedure (SOP)'],
        ];

        foreach ($docs as $d) {
            DB::table('DMS.documents')->updateOrInsert(
                ['title' => $d['title']],
                [
                    'uuid' => (string) Str::uuid(),
                    'folder_id' => $fldIds[$d['folder']] ?? 1,
                    'doc_type_id' => $dtIds[$d['type']] ?? 1,
                    'status' => 'approved',
                    'legal_hold' => false,
                    'effective_date' => now()->startOfYear()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedSchedule(): void
    {
        // 1. Resource Types (5 Types)
        $resTypes = [
            ['code' => 'ROOM', 'name' => 'Ruang Meeting & Konferensi'],
            ['code' => 'FLEET', 'name' => 'Kendaraan Operasional Logistik'],
            ['code' => 'ROASTER', 'name' => 'Mesin Roaster Lab & Pelatihan'],
            ['code' => 'MEDIA', 'name' => 'Proyektor & Media Presentasi'],
            ['code' => 'BOOTH', 'name' => 'Booth Pameran & Event Portable'],
        ];

        $rtIds = [];
        foreach ($resTypes as $rt) {
            $existing = DB::table('SCHEDULE.resource_types')->where('code', $rt['code'])->first();
            if (!$existing) {
                $id = DB::table('SCHEDULE.resource_types')->insertGetId([
                    'code' => $rt['code'],
                    'name' => $rt['name'],
                    'is_active' => true,
                ]);
                $rtIds[$rt['name']] = $id;
            } else {
                $rtIds[$rt['name']] = $existing->id;
            }
        }

        // 2. Resources (5 Resources)
        $resources = [
            ['name' => 'Meeting Room VIP (Lantai 2)', 'type' => 'Ruang Meeting & Konferensi', 'cap' => 12],
            ['name' => 'Meeting Room Diskusi (Lantai 1)', 'type' => 'Ruang Meeting & Konferensi', 'cap' => 6],
            ['name' => 'Blind Van Logistik W-1234-AB', 'type' => 'Kendaraan Operasional Logistik', 'cap' => 2],
            ['name' => 'Mesin Roasting Cupping Lab 1kg', 'type' => 'Mesin Roaster Lab & Pelatihan', 'cap' => 4],
            ['name' => 'Booth Portable Display Mall 01', 'type' => 'Booth Pameran & Event Portable', 'cap' => 3],
        ];

        foreach ($resources as $r) {
            DB::table('SCHEDULE.resources')->updateOrInsert(
                ['name' => $r['name']],
                [
                    'resource_type_id' => $rtIds[$r['type']] ?? 1,
                    'capacity' => $r['cap'],
                    'is_active' => true,
                ]
            );
        }

        // 3. Sched Items (5 Items)
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;
        for ($i = 1; $i <= 5; $i++) {
            $itemTitle = 'Jadwal Agenda Operasional ' . $i;
            $existingItem = DB::table('SCHEDULE.sched_items')->where('title', $itemTitle)->first();
            if (!$existingItem) {
                DB::table('SCHEDULE.sched_items')->insert([
                    'uuid' => (string) Str::uuid(),
                    'type' => 'event',
                    'title' => $itemTitle,
                    'description' => 'Koordinasi berkala tim operasional ' . $i,
                    'owner_id' => $adminId,
                    'status' => 'confirmed',
                    'priority' => 'normal',
                    'start_at' => now()->addDays($i)->setTime(10, 0),
                    'end_at' => now()->addDays($i)->setTime(11, 30),
                    'all_day' => false,
                    'location' => 'Meeting Room VIP (Lantai 2)',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedProjects(): void
    {
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;

        // 5 Projects
        $projects = [
            ['code' => 'PRJ-POS-2026', 'name' => 'Rollout POS & KDS Resto Multi-Cabang', 'status' => 'active'],
            ['code' => 'PRJ-CORETAX-01', 'name' => 'Kepatuhan E-Faktur Coretax DJP 2026', 'status' => 'active'],
            ['code' => 'PRJ-IOT-GUDANG', 'name' => 'Sensor Suhu & IoT Monitoring Gudang', 'status' => 'active'],
            ['code' => 'PRJ-APP-MOBILE', 'name' => 'Aplikasi Member & Loyalitas Pelanggan', 'status' => 'active'],
            ['code' => 'PRJ-EXPANSI-OUTLET', 'name' => 'Pembukaan Cabang Baru Surabaya Barat', 'status' => 'active'],
        ];

        foreach ($projects as $prj) {
            $existing = DB::table('PROJECTS.projects')->where('code', $prj['code'])->first();
            if (!$existing) {
                $pId = DB::table('PROJECTS.projects')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'code' => $prj['code'],
                    'name' => $prj['name'],
                    'description' => $prj['name'] . ' - Milestone implementasi tahun 2026.',
                    'status' => $prj['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $pId = $existing->id;
            }

            // 5 Issues
            $issueCode = 'ISS-' . $prj['code'];
            DB::table('PROJECTS.issues')->updateOrInsert(
                ['code' => $issueCode],
                [
                    'uuid' => (string) Str::uuid(),
                    'project_id' => $pId,
                    'title' => 'Deliverable Utama: ' . $prj['name'],
                    'description' => 'Tugas penanggung jawab operasional dan pengujian sistem.',
                    'type' => 'task',
                    'status' => 'in_progress',
                    'priority' => 'high',
                    'assignee_id' => $adminId,
                    'reporter_id' => $adminId,
                    'due_date' => now()->addDays(30)->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function seedPerformance(): void
    {
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;

        // 1. Perspectives (5 Perspectives)
        $perspectives = [
            ['name' => 'Financial Growth', 'description' => 'Target pendapatan, efisiensi biaya, dan profit margin.'],
            ['name' => 'Customer Satisfaction', 'description' => 'Kualitas layanan, loyalitas pelanggan B2B/Retail, dan SLA.'],
            ['name' => 'Operational Excellence', 'description' => 'Produktivitas pabrik, efisiensi gudang, dan pengurangan defect.'],
            ['name' => 'Learning & Innovation', 'description' => 'Pengembangan SDM, sertifikasi staf, dan adopsi modul ERP.'],
            ['name' => 'ESG & Sustainability', 'description' => 'Inisiatif kemasan ramah lingkungan dan efisiensi energi hijau.'],
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
            ['label' => 'FY 2026', 'type' => 'annual', 'year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31'],
            ['label' => 'Q1 2026', 'type' => 'quarterly', 'year' => 2026, 'start' => '2026-01-01', 'end' => '2026-03-31'],
            ['label' => 'Q2 2026', 'type' => 'quarterly', 'year' => 2026, 'start' => '2026-04-01', 'end' => '2026-06-30'],
            ['label' => 'Q3 2026', 'type' => 'quarterly', 'year' => 2026, 'start' => '2026-07-01', 'end' => '2026-09-30'],
            ['label' => 'Q4 2026', 'type' => 'quarterly', 'year' => 2026, 'start' => '2026-10-01', 'end' => '2026-12-31'],
        ];

        $periodIds = [];
        foreach ($periods as $prd) {
            $existing = DB::table('PERF.periods')->where('label', $prd['label'])->first();
            if (!$existing) {
                $id = DB::table('PERF.periods')->insertGetId([
                    'label' => $prd['label'],
                    'period_type' => $prd['type'],
                    'year' => $prd['year'],
                    'start_date' => $prd['start'],
                    'end_date' => $prd['end'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $periodIds[$prd['label']] = $id;
            } else {
                $periodIds[$prd['label']] = $existing->id;
            }
        }

        // 3. KPI Definitions & Targets (5 KPIs)
        $kpis = [
            [
                'name' => 'Pertumbuhan Penjualan Kasir POS',
                'direction' => 'higher_is_better',
                'perspective' => 'Financial Growth',
                'target' => 500000000,
                'actual' => 540000000,
            ],
            [
                'name' => 'Skor Kepuasan Pelanggan (CSAT)',
                'direction' => 'higher_is_better',
                'perspective' => 'Customer Satisfaction',
                'target' => 90.0,
                'actual' => 94.5,
            ],
            [
                'name' => 'Overall Equipment Effectiveness (OEE) Mesin',
                'direction' => 'higher_is_better',
                'perspective' => 'Operational Excellence',
                'target' => 85.0,
                'actual' => 88.2,
            ],
            [
                'name' => 'Tingkat Kehadiran & Kedisiplinan Staf (On-Time %)',
                'direction' => 'higher_is_better',
                'perspective' => 'Learning & Innovation',
                'target' => 95.0,
                'actual' => 97.1,
            ],
            [
                'name' => 'Rasio Penggunaan Kemasan Ramah Lingkungan',
                'direction' => 'higher_is_better',
                'perspective' => 'ESG & Sustainability',
                'target' => 80.0,
                'actual' => 85.0,
            ],
        ];

        $targetPeriodId = $periodIds['FY 2026'] ?? reset($periodIds);
        foreach ($kpis as $k) {
            $pId = $perspIds[$k['perspective']] ?? reset($perspIds);
            $existing = DB::table('PERF.kpi_definitions')->where('name', $k['name'])->first();
            if (!$existing) {
                $kId = DB::table('PERF.kpi_definitions')->insertGetId([
                    'perspective_id' => $pId,
                    'name' => $k['name'],
                    'description' => 'Indikator kinerja strategis ' . $k['name'],
                    'unit' => 'numeric',
                    'direction' => $k['direction'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $kId = $existing->id;
            }

            if ($targetPeriodId) {
                DB::table('PERF.targets')->updateOrInsert(
                    ['kpi_id' => $kId, 'period_id' => $targetPeriodId],
                    [
                        'subject_type' => 'company',
                        'target_value' => $k['target'],
                        'stretch_value' => $k['target'] * 1.1,
                        'notes' => 'Target disetujui direksi.',
                        'created_by' => $adminId,
                        'updated_at' => now(),
                    ]
                );

                DB::table('PERF.kpi_values')->updateOrInsert(
                    ['kpi_id' => $kId, 'period_id' => $targetPeriodId],
                    [
                        'subject_type' => 'company',
                        'actual_value' => $k['actual'],
                        'source' => 'manual',
                        'entered_by' => $adminId,
                        'entered_at' => now(),
                    ]
                );
            }
        }

        // 4. OKR Objectives (5 Objectives)
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
                'krs' => [
                    ['desc' => 'Tingkatkan akurasi pemenuhan pesanan (OTIF) hingga 98%', 'start' => 90, 'current' => 96, 'target' => 98],
                    ['desc' => 'Kurangi lead time roasting biji kopi dari 3 hari menjadi 1 hari', 'start' => 3, 'current' => 1.5, 'target' => 1],
                ],
            ],
            [
                'text' => 'Ekspansi Jaringan Retail POS dan Pelayanan Restoran Premium',
                'krs' => [
                    ['desc' => 'Implementasi sistem kasir POS & KDS di seluruh cabang aktif', 'start' => 0, 'current' => 1, 'target' => 1],
                    ['desc' => 'Tingkatkan rata-rata nilai transaksi (basket size) sebesar 20%', 'start' => 0, 'current' => 15, 'target' => 20],
                ],
            ],
            [
                'text' => 'Transformasi Digital dan Otomasi Pembukuan Keuangan Akuntansi',
                'krs' => [
                    ['desc' => 'Otomasi posting jurnal penutupan shift kasir 100%', 'start' => 50, 'current' => 100, 'target' => 100],
                    ['desc' => 'Kepatuhan pelaporan e-Faktur Coretax tepat waktu', 'start' => 0, 'current' => 1, 'target' => 1],
                ],
            ],
            [
                'text' => 'Peningkatan Retensi dan Kepuasan Pelanggan Korporat B2B',
                'krs' => [
                    ['desc' => 'Pertahankan angka retensi pelanggan B2B di atas 95%', 'start' => 85, 'current' => 94, 'target' => 95],
                    ['desc' => 'Resolusi tiket keluhan pelanggan di bawah 4 jam', 'start' => 8, 'current' => 3.5, 'target' => 4],
                ],
            ],
            [
                'text' => 'Inisiatif Pabrik Hijau dan Pengurangan Limbah Sisa Kemasan',
                'krs' => [
                    ['desc' => 'Turunkan rasio sisa bahan baku (scrap/waste) di bawah 1.5%', 'start' => 4.0, 'current' => 1.8, 'target' => 1.5],
                    ['desc' => '100% outlet retail mengadopsi paper cup biodegradable', 'start' => 20, 'current' => 80, 'target' => 100],
                ],
            ],
        ];

        foreach ($objectives as $obj) {
            $existingObj = DB::table('PERF.okr_objectives')->where('cycle_id', $cycleId)->where('objective_text', $obj['text'])->first();
            if (!$existingObj) {
                $objId = DB::table('PERF.okr_objectives')->insertGetId([
                    'cycle_id' => $cycleId,
                    'subject_type' => 'company',
                    'objective_text' => $obj['text'],
                    'status' => 'on_track',
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $objId = $existingObj->id;
            }

            foreach ($obj['krs'] as $kr) {
                DB::table('PERF.okr_key_results')->updateOrInsert(
                    ['okr_id' => $objId, 'description' => $kr['desc']],
                    [
                        'metric_type' => 'numeric',
                        'start_value' => $kr['start'],
                        'current_value' => $kr['current'],
                        'target_value' => $kr['target'],
                        'weight' => 50,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        // 5. Badges (5 Badges) & Achievements (5 Achievements)
        $badges = [
            ['name' => 'Top Sales Hero 2026', 'desc' => 'Pencapaian omzet penjualan tertinggi kuartal ini.'],
            ['name' => 'Master Barista Craft', 'desc' => 'Kualitas seduhan dan latte art standar internasional.'],
            ['name' => 'Zero Downtime Champion', 'desc' => 'Pemeliharaan preventif mesin tanpa kendala teknis.'],
            ['name' => 'Best Attendance Award', 'desc' => 'Kedisiplinan shift kerja sempurna 100% tepat waktu.'],
            ['name' => 'Eco-Innovation Pioneer', 'desc' => 'Penggagas efisiensi bahan dan pengolahan ramah lingkungan.'],
        ];

        $employees = DB::table('HCM.employees')->get();
        foreach ($badges as $idx => $b) {
            $existingB = DB::table('PERF.badge_definitions')->where('name', $b['name'])->first();
            if (!$existingB) {
                $bId = DB::table('PERF.badge_definitions')->insertGetId([
                    'name' => $b['name'],
                    'trigger_type' => 'manual',
                    'icon' => 'Award',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $bId = $existingB->id;
            }

            if ($employees->count() > 0) {
                $emp = $employees[$idx % $employees->count()];
                DB::table('PERF.achievements')->updateOrInsert(
                    ['subject_type' => 'employee', 'subject_id' => $emp->id, 'badge_id' => $bId],
                    ['earned_at' => now()->subDays(10 - $idx), 'awarded_by' => $adminId]
                );
            }
        }

        // 6. Budget Lines (5 Lines)
        $bgtPeriodId = $periodIds['FY 2026'] ?? reset($periodIds);
        if ($bgtPeriodId) {
            $existingBgt = DB::table('PERF.budget_hdrs')->where('name', 'Rencana Anggaran Operasional 2026')->first();
            if (!$existingBgt) {
                $bgtId = DB::table('PERF.budget_hdrs')->insertGetId([
                    'name' => 'Rencana Anggaran Operasional 2026',
                    'subject_type' => 'company',
                    'fiscal_year' => 2026,
                    'status' => 'approved',
                    'owner_id' => $adminId,
                    'version_no' => 1,
                    'notes' => 'Anggaran operasional tahunan yang telah disetujui direksi.',
                    'created_by' => $adminId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $bgtId = $existingBgt->id;
            }

            $lines = [
                ['category' => 'Biaya Produksi & Bahan Baku', 'amount' => 250000000],
                ['category' => 'Gaji & Operasional Karyawan', 'amount' => 480000000],
                ['category' => 'Pengembangan IT & Lisensi Cloud', 'amount' => 120000000],
                ['category' => 'Logistik & Pemeliharaan Mesin', 'amount' => 75000000],
                ['category' => 'Inisiatif Keberlanjutan ESG Hijau', 'amount' => 50000000],
            ];

            foreach ($lines as $line) {
                DB::table('PERF.budget_lines')->updateOrInsert(
                    ['budget_id' => $bgtId, 'category' => $line['category']],
                    [
                        'period_id' => $bgtPeriodId,
                        'amount_planned' => $line['amount'],
                        'notes' => 'Plafon alokasi ' . $line['category'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedPp(): void
    {
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;

        $prodEsp = DB::table('INVENTORY.products')->where('sku', 'ESP-001')->first();
        $prodCap = DB::table('INVENTORY.products')->where('sku', 'CAP-001')->first();
        $prodNas = DB::table('INVENTORY.products')->where('sku', 'NAS-001')->first();
        $prodMie = DB::table('INVENTORY.products')->where('sku', 'MIE-001')->first();
        $prodCro = DB::table('INVENTORY.products')->where('sku', 'CRO-001')->first();

        $rawCof = DB::table('INVENTORY.products')->where('sku', 'RAW-COF-01')->first();
        $rawMlk = DB::table('INVENTORY.products')->where('sku', 'RAW-MLK-01')->first();
        $rawCup = DB::table('INVENTORY.products')->where('sku', 'RAW-CUP-01')->first();
        $rawRic = DB::table('INVENTORY.products')->where('sku', 'RAW-RIC-01')->first();
        $rawPkg = DB::table('INVENTORY.products')->where('sku', 'PKG-BOX-01')->first();

        // 1. BOMs (5 BOMs)
        $bomsData = [
            ['prod' => $prodEsp, 'code' => 'BOM-ESP-01', 'items' => [[$rawCof, 0.018], [$rawCup, 1.0]]],
            ['prod' => $prodCap, 'code' => 'BOM-CAP-01', 'items' => [[$rawCof, 0.018], [$rawMlk, 0.15], [$rawCup, 1.0]]],
            ['prod' => $prodNas, 'code' => 'BOM-NAS-01', 'items' => [[$rawRic, 0.15], [$rawPkg, 1.0]]],
            ['prod' => $prodMie, 'code' => 'BOM-MIE-01', 'items' => [[$rawRic, 0.12], [$rawPkg, 1.0]]],
            ['prod' => $prodCro, 'code' => 'BOM-CRO-01', 'items' => [[$rawMlk, 0.08], [$rawPkg, 1.0]]],
        ];

        $bomIds = [];
        foreach ($bomsData as $b) {
            if ($b['prod']) {
                $existing = DB::table('PP.pp_boms')->where('product_id', $b['prod']->id)->first();
                if (!$existing) {
                    $bId = DB::table('PP.pp_boms')->insertGetId([
                        'product_id' => $b['prod']->id,
                        'version' => 1,
                        'effective_from' => '2026-01-01',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $bomIds[$b['code']] = $bId;

                    foreach ($b['items'] as [$rawProd, $qty]) {
                        if ($rawProd) {
                            DB::table('PP.pp_bom_lines')->insert([
                                'bom_id' => $bId,
                                'component_product_id' => $rawProd->id,
                                'qty_per_parent_unit' => $qty,
                                'scrap_pct' => 2.0,
                            ]);
                        }
                    }
                } else {
                    $bomIds[$b['code']] = $existing->id;
                }
            }
        }

        // 2. Recipes (5 Recipes)
        $recipesData = [
            ['prod' => $prodEsp, 'batch' => 1.0, 'yield' => 98.0, 'waste' => 2.0],
            ['prod' => $prodCap, 'batch' => 1.0, 'yield' => 97.0, 'waste' => 3.0],
            ['prod' => $prodNas, 'batch' => 1.0, 'yield' => 95.0, 'waste' => 5.0],
            ['prod' => $prodMie, 'batch' => 1.0, 'yield' => 95.0, 'waste' => 5.0],
            ['prod' => $prodCro, 'batch' => 1.0, 'yield' => 96.0, 'waste' => 4.0],
        ];

        $recipeIds = [];
        foreach ($recipesData as $idx => $r) {
            if ($r['prod']) {
                $existing = DB::table('PP.pp_recipes')->where('product_id', $r['prod']->id)->first();
                if (!$existing) {
                    $rId = DB::table('PP.pp_recipes')->insertGetId([
                        'product_id' => $r['prod']->id,
                        'version' => 1,
                        'batch_size' => $r['batch'],
                        'expected_yield_pct' => $r['yield'],
                        'expected_waste_pct' => $r['waste'],
                        'effective_from' => '2026-01-01',
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $recipeIds[$idx] = $rId;

                    if ($rawCof) {
                        DB::table('PP.pp_recipe_ingredients')->insert([
                            'recipe_id' => $rId,
                            'raw_material_product_id' => $rawCof->id,
                            'qty_per_batch' => 0.018,
                        ]);
                    }
                } else {
                    $recipeIds[$idx] = $existing->id;
                }
            }
        }

        // 3. Item Planning Parameters (5 Items)
        $planProducts = [$prodEsp, $prodCap, $prodNas, $prodMie, $prodCro];
        foreach ($planProducts as $p) {
            if ($p) {
                DB::table('PP.pp_item_planning_params')->updateOrInsert(
                    ['product_id' => $p->id],
                    [
                        'make_type' => 'mts',
                        'min_lot_qty' => 10.0,
                        'max_lot_qty' => 500.0,
                        'safety_stock_qty' => 20.0,
                        'lead_time_days' => 1,
                        'order_multiple' => 1.0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        // 4. Resources (5 Resources)
        $resources = [
            ['code' => 'RES-ROASTER-01', 'name' => 'Mesin Roasting Biji Kopi Gayo 5kg', 'cap' => 15.0],
            ['code' => 'RES-ESPRESSO-01', 'name' => 'Commercial Espresso Machine 3-Group', 'cap' => 120.0],
            ['code' => 'RES-WOK-01', 'name' => 'Kompor Gas High Pressure Wok Station', 'cap' => 25.0],
            ['code' => 'RES-OVEN-01', 'name' => 'Deck Oven Bakery Konveksi 3-Deck', 'cap' => 40.0],
            ['code' => 'RES-SEALER-01', 'name' => 'Mesin Cup Sealer Otomatis', 'cap' => 200.0],
        ];

        foreach ($resources as $res) {
            DB::table('PP.pp_resources')->updateOrInsert(
                ['code' => $res['code']],
                [
                    'type' => 'machine',
                    'name' => $res['name'],
                    'capacity' => $res['cap'],
                    'is_active' => true,
                ]
            );
        }

        // 5. Planned Orders (5 Orders)
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

        for ($i = 1; $i <= 5; $i++) {
            $planNum = sprintf('PLAN-2026-%04d', $i);
            $existingPlan = DB::table('PP.pp_planned_orders')->where('plan_number', $planNum)->first();
            $p = $planProducts[($i - 1) % count($planProducts)];
            if (!$existingPlan && $p) {
                $bRow = DB::table('PP.pp_boms')->where('product_id', $p->id)->first();
                $planId = DB::table('PP.pp_planned_orders')->insertGetId([
                    'mrp_run_id' => $mrpRunId,
                    'plan_number' => $planNum,
                    'order_type' => 'production',
                    'product_id' => $p->id,
                    'qty' => 100.0 * $i,
                    'need_by_date' => now()->addDays(2 + $i)->toDateString(),
                    'bom_id' => $bRow?->id,
                    'status' => 'firmed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('PP.pp_schedule_ops')->insert([
                    'planned_order_id' => $planId,
                    'seq' => 1,
                    'resource_type' => 'mes_work_center',
                    'planned_start' => now()->addHours($i),
                    'planned_end' => now()->addHours($i + 2),
                    'status' => 'committed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedMes(): void
    {
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;

        // 1. Work Centers (5 Work Centers)
        $wcs = [
            ['code' => 'WC-COFFEE', 'name' => 'Pusat Roasting & Pengolahan Kopi', 'type' => 'discrete'],
            ['code' => 'WC-KITCHEN', 'name' => 'Dapur Utama Makanan Panas', 'type' => 'batch'],
            ['code' => 'WC-PACK', 'name' => 'Pusat Pengemasan & Cup Sealing', 'type' => 'line'],
            ['code' => 'WC-BAKE', 'name' => 'Area Baking & Pastry Bakery', 'type' => 'batch'],
            ['code' => 'WC-QC', 'name' => 'Laboratorium Pengujian Mutu & QC', 'type' => 'discrete'],
        ];

        $wcIds = [];
        foreach ($wcs as $w) {
            $existing = DB::table('MES.mes_work_centers')->where('code', $w['code'])->first();
            if (!$existing) {
                $id = DB::table('MES.mes_work_centers')->insertGetId([
                    'code' => $w['code'],
                    'name' => $w['name'],
                    'type' => $w['type'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $wcIds[$w['code']] = $id;
            } else {
                $wcIds[$w['code']] = $existing->id;
            }
        }

        // 2. Machines (5 Machines)
        $machines = [
            ['code' => 'M-ROASTER-01', 'name' => 'Commercial Coffee Roaster 5kg', 'wc' => 'WC-COFFEE'],
            ['code' => 'M-ESPRESSO-01', 'name' => 'Espresso Extraction Machine 3-Group', 'wc' => 'WC-COFFEE'],
            ['code' => 'M-WOK-01', 'name' => 'High-Flame Commercial Stove Wok', 'wc' => 'WC-KITCHEN'],
            ['code' => 'M-OVEN-01', 'name' => 'Industrial Deck Baking Oven 3-Tier', 'wc' => 'WC-BAKE'],
            ['code' => 'M-SEALER-01', 'name' => 'Automatic Rotary Cup Sealer', 'wc' => 'WC-PACK'],
        ];

        $machineIds = [];
        foreach ($machines as $m) {
            $existing = DB::table('MES.mes_machines')->where('code', $m['code'])->first();
            if (!$existing) {
                $id = DB::table('MES.mes_machines')->insertGetId([
                    'work_center_id' => $wcIds[$m['wc']] ?? 1,
                    'code' => $m['code'],
                    'name' => $m['name'],
                    'status' => 'operational',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $machineIds[$m['code']] = $id;
            } else {
                $machineIds[$m['code']] = $existing->id;
            }
        }

        // 3. Stations (5 Stations)
        $stations = [
            ['code' => 'ST-BEV-01', 'name' => 'Stasiun Peracikan Minuman', 'wc' => 'WC-COFFEE'],
            ['code' => 'ST-COOK-01', 'name' => 'Stasiun Masak Hidangan Panas', 'wc' => 'WC-KITCHEN'],
            ['code' => 'ST-BAKE-01', 'name' => 'Stasiun Pemanggangan Roti Pastry', 'wc' => 'WC-BAKE'],
            ['code' => 'ST-PACK-01', 'name' => 'Stasiun Packing & Labeling', 'wc' => 'WC-PACK'],
            ['code' => 'ST-QC-01', 'name' => 'Stasiun Cupping & Uji Kualitas', 'wc' => 'WC-QC'],
        ];

        foreach ($stations as $s) {
            $existing = DB::table('MES.mes_stations')->where('code', $s['code'])->first();
            if (!$existing) {
                DB::table('MES.mes_stations')->insert([
                    'work_center_id' => $wcIds[$s['wc']] ?? 1,
                    'code' => $s['code'],
                    'name' => $s['name'],
                ]);
            }
        }

        // 4. Routings (5 Routings)
        $prodEsp = DB::table('INVENTORY.products')->where('sku', 'ESP-001')->first();
        $prodCap = DB::table('INVENTORY.products')->where('sku', 'CAP-001')->first();
        $prodNas = DB::table('INVENTORY.products')->where('sku', 'NAS-001')->first();
        $prodMie = DB::table('INVENTORY.products')->where('sku', 'MIE-001')->first();
        $prodCro = DB::table('INVENTORY.products')->where('sku', 'CRO-001')->first();
        $routeProducts = [$prodEsp, $prodCap, $prodNas, $prodMie, $prodCro];

        foreach ($routeProducts as $idx => $p) {
            if ($p) {
                $existingR = DB::table('MES.mes_routings')->where('product_id', $p->id)->first();
                if (!$existingR) {
                    $rId = DB::table('MES.mes_routings')->insertGetId([
                        'product_id' => $p->id,
                        'version' => 1,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('MES.mes_routing_ops')->insert([
                        'routing_id' => $rId,
                        'seq' => 1,
                        'op_code' => 'OP-01',
                        'op_name' => 'Persiapan & Pengolahan Bahan Utama',
                        'work_center_id' => $wcIds['WC-COFFEE'] ?? 1,
                        'setup_time_minutes' => 5,
                        'run_time_minutes' => 10,
                    ]);
                }
            }
        }

        // 5. Production Orders (5 Orders) & Batches (5 Batches)
        $recipeEsp = DB::table('PP.pp_recipes')->first();
        $bomEsp = DB::table('PP.pp_boms')->first();

        for ($i = 1; $i <= 5; $i++) {
            $moNum = sprintf('MO-2026-%04d', $i);
            $existingMo = DB::table('MES.mes_prod_order_hdrs')->where('order_number', $moNum)->first();
            $p = $routeProducts[($i - 1) % count($routeProducts)];
            if (!$existingMo && $p) {
                $moId = DB::table('MES.mes_prod_order_hdrs')->insertGetId([
                    'order_number' => $moNum,
                    'product_id' => $p->id,
                    'production_model' => 'discrete',
                    'bom_id' => $bomEsp?->id,
                    'recipe_id' => $recipeEsp?->id,
                    'qty' => 100.0,
                    'status' => $i <= 3 ? 'completed' : 'in_progress',
                    'priority' => 'medium',
                    'planned_start' => now()->subDays(6 - $i)->setTime(8, 0),
                    'planned_end' => now()->subDays(6 - $i)->setTime(16, 0),
                    'created_at' => now()->subDays(6 - $i),
                    'updated_at' => now()->subDays(6 - $i),
                ]);
            } else {
                $moId = $existingMo?->id;
            }

            if ($moId && $p) {
                // Batch
                $batchNum = sprintf('BATCH-202609-%03d', $i);
                $existingBatch = DB::table('MES.mes_batches')->where('batch_number', $batchNum)->first();
                if (!$existingBatch) {
                    DB::table('MES.mes_batches')->insert([
                        'order_id' => $moId,
                        'batch_number' => $batchNum,
                        'recipe_id' => $recipeEsp?->id ?? 1,
                        'status' => $i <= 3 ? 'completed' : 'running',
                        'planned_qty' => 100.0,
                        'actual_yield_pct' => 99.0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Outputs
                $existingOutput = DB::table('MES.mes_production_outputs')->where('order_id', $moId)->first();
                if (!$existingOutput) {
                    DB::table('MES.mes_production_outputs')->insert([
                        'order_id' => $moId,
                        'output_type' => 'finished',
                        'product_id' => $p->id,
                        'qty' => 99.0,
                        'created_at' => now(),
                    ]);
                }

                // Downtime events (5 Events)
                $existingDt = DB::table('MES.mes_downtime_events')->where('order_id', $moId)->first();
                if (!$existingDt) {
                    DB::table('MES.mes_downtime_events')->insert([
                        'machine_id' => $machineIds['M-ESPRESSO-01'] ?? 1,
                        'order_id' => $moId,
                        'category' => 'unplanned',
                        'reason_code' => 'mechanical',
                        'started_at' => now()->subDays(6 - $i)->setTime(10, 0),
                        'ended_at' => now()->subDays(6 - $i)->setTime(10, 25),
                        'started_by' => $adminId,
                        'ended_by' => $adminId,
                    ]);
                }

                // Andon Alerts (5 Alerts)
                $existingAndon = DB::table('MES.mes_andon_alerts')->where('message', 'like', '%batch ' . $i)->first();
                if (!$existingAndon) {
                    DB::table('MES.mes_andon_alerts')->insert([
                        'alert_type' => 'machine_stopped',
                        'subject_type' => 'machine',
                        'subject_id' => $machineIds['M-ESPRESSO-01'] ?? 1,
                        'severity' => 'warning',
                        'message' => 'Sensor tekanan grup head memerlukan kalibrasi batch ' . $i,
                        'fired_at' => now()->subDays(6 - $i)->setTime(10, 0),
                        'resolved_at' => now()->subDays(6 - $i)->setTime(10, 25),
                    ]);
                }
            }
        }
    }

    private function seedWne(): void
    {
        $adminUser = DB::table('users')->first();
        $adminId = $adminUser?->id ?? 1;

        // 1. Workflow Categories (5 Categories)
        $wfCats = [
            'Persetujuan Pembelian & Keuangan',
            'Manajemen Personalia & SDM',
            'Operasional Pabrik & MES',
            'Pelayanan Pelanggan & Garansi',
            'Manajemen Mutu & Audit Legal',
        ];

        $catMap = [];
        foreach ($wfCats as $c) {
            $existing = DB::table('WNE.wrkflow_categories')->where('name', $c)->first();
            if (!$existing) {
                $id = DB::table('WNE.wrkflow_categories')->insertGetId([
                    'name' => $c,
                    'description' => 'Kategori alur kerja proses ' . $c,
                    'is_active' => true,
                ]);
                $catMap[$c] = $id;
            } else {
                $catMap[$c] = $existing->id;
            }
        }

        // 2. Workflow Definitions (5 Definitions)
        $defs = [
            ['code' => 'pur.po_approval', 'title' => 'Alur Persetujuan Purchase Order Bernilai Tinggi', 'cat' => 'Persetujuan Pembelian & Keuangan'],
            ['code' => 'hcm.leave_approval', 'title' => 'Alur Pengajuan & Persetujuan Cuti Karyawan', 'cat' => 'Manajemen Personalia & SDM'],
            ['code' => 'mes.maintenance_escalation', 'title' => 'Eskalasi Insiden Perbaikan Mesin Kritis', 'cat' => 'Operasional Pabrik & MES'],
            ['code' => 'acc.invoice_approval', 'title' => 'Verifikasi Pembayaran Faktur Tagihan Vendor', 'cat' => 'Persetujuan Pembelian & Keuangan'],
            ['code' => 'crm.lead_assignment', 'title' => 'Penetapan Sales Representative Prospek Baru', 'cat' => 'Pelayanan Pelanggan & Garansi'],
        ];

        foreach ($defs as $idx => $d) {
            $existing = DB::table('WNE.wrkflow_definitions')->where('code', $d['code'])->first();
            if (!$existing) {
                $defId = DB::table('WNE.wrkflow_definitions')->insertGetId([
                    'code' => $d['code'],
                    'name' => $d['title'],
                    'category_id' => $catMap[$d['cat']] ?? 1,
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

                $sId = DB::table('WNE.wrkflow_steps')->insertGetId([
                    'version_id' => $verId,
                    'step_code' => 'entry_step_' . $idx,
                    'type' => 'task',
                    'config' => json_encode(['label' => 'Tahap Pengajuan Awal']),
                    'is_entry_step' => true,
                ]);

                // 3. Workflow Instances (5 Instances)
                DB::table('WNE.wrkflow_instances')->insert([
                    'uuid' => (string) Str::uuid(),
                    'definition_version_id' => $verId,
                    'subject_type' => 'workflow_item',
                    'subject_id' => $idx + 1,
                    'status' => 'running',
                    'payload' => json_encode(['reference_code' => 'WF-2026-00' . ($idx + 1)]),
                    'started_by' => $adminId,
                    'started_at' => now()->subHours($idx + 1),
                ]);
            }
        }

        // 4. Message Categories (5 Categories)
        $msgCats = [
            ['code' => 'trans.po_approved', 'name' => 'Purchase Order Disetujui'],
            ['code' => 'hcm.leave_status', 'name' => 'Status Pengajuan Cuti'],
            ['code' => 'mes.machine_alert', 'name' => 'Peringatan Operasional Mesin Pabrik'],
            ['code' => 'acc.payment_received', 'name' => 'Konfirmasi Penerimaan Pembayaran'],
            ['code' => 'crm.ticket_update', 'name' => 'Pembaruan Tiket Layanan Konsumen'],
        ];

        foreach ($msgCats as $mc) {
            DB::table('WNE.msg_categories')->updateOrInsert(
                ['code' => $mc['code']],
                [
                    'name' => $mc['name'],
                    'is_mandatory' => false,
                    'digestible' => false,
                    'default_channels' => json_encode(['in_app', 'email']),
                    'is_urgent' => false,
                ]
            );

            // 5. Message Notifications & Deliveries (5 Notifications)
            $catRow = DB::table('WNE.msg_categories')->where('code', $mc['code'])->first();
            $nId = DB::table('WNE.msg_notifications')->insertGetId([
                'category_code' => $mc['code'],
                'recipient_type' => 'user',
                'recipient_user_id' => $adminId,
                'subject' => 'Notifikasi Sistem: ' . $mc['name'],
                'body' => 'Pembaruan otomatis dari sistem NusaEvo ERP untuk ' . $mc['name'],
                'data' => json_encode([]),
                'status' => 'delivered',
                'created_at' => now(),
            ]);

            DB::table('WNE.msg_notification_deliveries')->insert([
                'notification_id' => $nId,
                'channel' => 'in_app',
                'status' => 'delivered',
                'sent_at' => now(),
                'attempt' => 1,
                'retry_history' => json_encode([]),
            ]);
        }
    }
}
