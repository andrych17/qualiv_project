<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\CostCenter;
use App\Modules\Purchase\Models\PurBudget;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\VendorProfile;
use Illuminate\Database\Seeder;

class PurchaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Categories
        $categoriesData = [
            ['name' => 'IT & Hardware Equipment', 'kind' => 'direct', 'capex_opex' => 'capex'],
            ['name' => 'Cloud & Software Subscriptions', 'kind' => 'direct', 'capex_opex' => 'opex'],
            ['name' => 'Office Supplies & Stationery', 'kind' => 'indirect', 'capex_opex' => 'opex'],
            ['name' => 'Professional & Advisory Services', 'kind' => 'indirect', 'capex_opex' => 'opex'],
            ['name' => 'Logistics & Facility Operations', 'kind' => 'indirect', 'capex_opex' => 'opex'],
        ];

        $categories = [];
        foreach ($categoriesData as $c) {
            $categories[$c['name']] = Category::query()->updateOrCreate(
                ['name' => $c['name']],
                [
                    'kind' => $c['kind'],
                    'capex_opex' => $c['capex_opex'],
                    'is_active' => true,
                ]
            );
        }

        // 2. Cost Centers
        $costCentersData = [
            ['code' => 'CC-IT', 'name' => 'Information Technology & Engineering'],
            ['code' => 'CC-OPS', 'name' => 'Operations & Logistics'],
            ['code' => 'CC-FIN', 'name' => 'Finance & Accounting'],
            ['code' => 'CC-LEGAL', 'name' => 'Legal & Corporate Compliance'],
        ];

        $costCenters = [];
        foreach ($costCentersData as $cc) {
            $costCenters[$cc['code']] = CostCenter::query()->updateOrCreate(
                ['code' => $cc['code']],
                [
                    'name' => $cc['name'],
                    'is_active' => true,
                ]
            );
        }

        // 3. Budgets for current month & year
        $currentPeriod = now()->format('Y-m');
        if (isset($costCenters['CC-IT'], $categories['IT & Hardware Equipment'])) {
            PurBudget::query()->updateOrCreate(
                [
                    'period' => $currentPeriod,
                    'cost_center_id' => $costCenters['CC-IT']->id,
                    'category_id' => $categories['IT & Hardware Equipment']->id,
                ],
                [
                    'budget_amount' => 150000000.00,
                ]
            );
        }

        if (isset($costCenters['CC-OPS'], $categories['Office Supplies & Stationery'])) {
            PurBudget::query()->updateOrCreate(
                [
                    'period' => $currentPeriod,
                    'cost_center_id' => $costCenters['CC-OPS']->id,
                    'category_id' => $categories['Office Supplies & Stationery']->id,
                ],
                [
                    'budget_amount' => 35000000.00,
                ]
            );
        }

        // 4. Vendors (via CRM Partners with role 'vendor')
        $vendorPartners = [
            [
                'name' => 'PT Mitra Solusi Teknologi',
                'tax_no' => '01.234.567.8-012.000',
                'bank' => 'BCA',
                'terms' => 30,
            ],
            [
                'name' => 'CV Sumber Kertas Nusantara',
                'tax_no' => '02.345.678.9-034.000',
                'bank' => 'Mandiri',
                'terms' => 14,
            ],
            [
                'name' => 'PT Prima Logistik Ekpres',
                'tax_no' => '03.456.789.0-056.000',
                'bank' => 'BNI',
                'terms' => 45,
            ],
        ];

        $vendorProfiles = [];
        foreach ($vendorPartners as $vp) {
            $partner = Partner::query()->firstOrCreate(
                ['name' => $vp['name']],
                [
                    'type' => Partner::TYPE_ORGANIZATION,
                    'registration_tax_id' => $vp['tax_no'],
                    'is_active' => true,
                ]
            );

            // Ensure vendor role assigned in CRM
            PartnerRoleType::query()->firstOrCreate(
                ['partner_id' => $partner->id, 'role_type' => 'vendor'],
                ['is_active' => true]
            );

            $profile = VendorProfile::query()->updateOrCreate(
                ['partner_id' => $partner->id],
                [
                    'payment_terms_days' => $vp['terms'],
                    'preferred_currency' => 'IDR',
                    'tax_registration_no' => $vp['tax_no'],
                    'bank_name' => $vp['bank'],
                    'is_preferred' => true,
                    'onboarding_status' => 'active',
                ]
            );

            $vendorProfiles[$vp['name']] = [
                'partner' => $partner,
                'profile' => $profile,
            ];
        }

        // 5. Catalog items
        $catalogItems = [
            [
                'item_code' => 'IT-SRV-CLOUD',
                'description' => 'Dedicated Cloud Compute Node (8 vCPU, 32GB RAM)',
                'category' => 'Cloud & Software Subscriptions',
                'unit' => 'month',
                'price' => 3500000.00,
                'vendor' => 'PT Mitra Solusi Teknologi',
            ],
            [
                'item_code' => 'IT-MON-4K',
                'description' => 'Ultra-wide 34-inch Professional Monitor',
                'category' => 'IT & Hardware Equipment',
                'unit' => 'unit',
                'price' => 6200000.00,
                'vendor' => 'PT Mitra Solusi Teknologi',
            ],
            [
                'item_code' => 'OFF-PPR-A4',
                'description' => 'A4 80gsm High Performance Paper (Box / 5 Reams)',
                'category' => 'Office Supplies & Stationery',
                'unit' => 'box',
                'price' => 245000.00,
                'vendor' => 'CV Sumber Kertas Nusantara',
            ],
            [
                'item_code' => 'LOG-CARGO-CONTAINER',
                'description' => 'Domestic Inter-Island Cargo Freight (Per 20ft TEU)',
                'category' => 'Logistics & Facility Operations',
                'unit' => 'shipment',
                'price' => 8500000.00,
                'vendor' => 'PT Prima Logistik Ekpres',
            ],
        ];

        foreach ($catalogItems as $item) {
            $catId = $categories[$item['category']]->id ?? null;
            $vendorId = $vendorProfiles[$item['vendor']]['partner']->id ?? null;

            PurCatalogItem::query()->updateOrCreate(
                ['item_code' => $item['item_code']],
                [
                    'description' => $item['description'],
                    'category_id' => $catId,
                    'unit' => $item['unit'],
                    'preferred_supplier_id' => $vendorId,
                    'negotiated_price' => $item['price'],
                    'price_valid_from' => now()->startOfYear()->toDateString(),
                    'price_valid_to' => now()->endOfYear()->toDateString(),
                    'source' => 'manual',
                    'is_active' => true,
                ]
            );
        }
    }
}
