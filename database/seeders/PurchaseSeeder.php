<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\CostCenter;
use App\Modules\Purchase\Models\PurBudget;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\PurContractHdr;
use App\Modules\Purchase\Models\PurException;
use App\Modules\Purchase\Models\PurInvoiceHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurReceiptHdr;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Models\PurRfxHdr;
use App\Modules\Purchase\Models\VendorProfile;
use Illuminate\Database\Seeder;

class PurchaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@nusaevo.com')->first() ?? User::query()->first();
        $staff = User::query()->where('email', 'staff@nusaevo.com')->first() ?? $admin;

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
            $vendorRoleTypeId = PartnerRoleType::query()->where('code', 'VENDOR')->value('id') ?? 2;
            PartnerRole::query()->firstOrCreate(
                ['partner_id' => $partner->id, 'role_type_id' => $vendorRoleTypeId],
                ['is_active' => true, 'assigned_at' => now()]
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

        $catalogMap = [];
        foreach ($catalogItems as $item) {
            $catId = $categories[$item['category']]->id ?? null;
            $vendorId = $vendorProfiles[$item['vendor']]['partner']->id ?? null;

            $catalogMap[$item['item_code']] = PurCatalogItem::query()->updateOrCreate(
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

        // 6. Purchase Requisitions (PR)
        $pr1 = PurRequisitionHdr::query()->updateOrCreate(
            ['pr_no' => 'PR-202608-0001'],
            [
                'requester_id' => $staff?->id ?? $admin->id,
                'cost_center_id' => $costCenters['CC-IT']->id,
                'needed_by' => now()->addDays(10)->toDateString(),
                'status' => PurRequisitionHdr::STATUS_APPROVED,
                'estimated_total' => 45000000.00,
                'budget_warning' => false,
                'duplicate_warning' => false,
                'notes' => 'Procurement of development hardware and cloud compute nodes for Q3 expansion.',
                'created_by' => $staff?->id ?? $admin->id,
            ]
        );
        $pr1->lines()->delete();
        $pr1->lines()->createMany([
            [
                'line_no' => 1,
                'catalog_item_id' => $catalogMap['IT-MON-4K']->id,
                'description' => 'Ultra-wide 34-inch Professional Monitor',
                'qty' => 5,
                'estimated_unit_price' => 6200000.00,
                'category_id' => $categories['IT & Hardware Equipment']->id,
                'local_content_pct' => 40.0,
            ],
            [
                'line_no' => 2,
                'catalog_item_id' => $catalogMap['IT-SRV-CLOUD']->id,
                'description' => 'Dedicated Cloud Compute Node (8 vCPU, 32GB RAM)',
                'qty' => 4,
                'estimated_unit_price' => 3500000.00,
                'category_id' => $categories['Cloud & Software Subscriptions']->id,
                'local_content_pct' => 60.0,
            ],
        ]);

        $pr2 = PurRequisitionHdr::query()->updateOrCreate(
            ['pr_no' => 'PR-202608-0002'],
            [
                'requester_id' => $staff?->id ?? $admin->id,
                'cost_center_id' => $costCenters['CC-OPS']->id,
                'needed_by' => now()->addDays(5)->toDateString(),
                'status' => PurRequisitionHdr::STATUS_PENDING_APPROVAL,
                'estimated_total' => 12250000.00,
                'budget_warning' => false,
                'duplicate_warning' => false,
                'notes' => 'Monthly office supplies and high-performance printing paper for general operations.',
                'created_by' => $staff?->id ?? $admin->id,
            ]
        );
        $pr2->lines()->delete();
        $pr2->lines()->create([
            'line_no' => 1,
            'catalog_item_id' => $catalogMap['OFF-PPR-A4']->id,
            'description' => 'A4 80gsm High Performance Paper (Box / 5 Reams)',
            'qty' => 50,
            'estimated_unit_price' => 245000.00,
            'category_id' => $categories['Office Supplies & Stationery']->id,
            'local_content_pct' => 90.0,
        ]);

        $pr3 = PurRequisitionHdr::query()->updateOrCreate(
            ['pr_no' => 'PR-202608-0003'],
            [
                'requester_id' => $admin->id,
                'cost_center_id' => $costCenters['CC-OPS']->id,
                'needed_by' => now()->addDays(20)->toDateString(),
                'status' => PurRequisitionHdr::STATUS_DRAFT,
                'estimated_total' => 17000000.00,
                'budget_warning' => false,
                'duplicate_warning' => false,
                'notes' => 'Logistics container shipment for branch office material transfer.',
                'created_by' => $admin->id,
            ]
        );
        $pr3->lines()->delete();
        $pr3->lines()->create([
            'line_no' => 1,
            'catalog_item_id' => $catalogMap['LOG-CARGO-CONTAINER']->id,
            'description' => 'Domestic Inter-Island Cargo Freight (Per 20ft TEU)',
            'qty' => 2,
            'estimated_unit_price' => 8500000.00,
            'category_id' => $categories['Logistics & Facility Operations']->id,
            'local_content_pct' => 100.0,
        ]);

        // 7. Purchase Orders (PO)
        $po1 = PurOrderHdr::query()->updateOrCreate(
            ['po_no' => 'PO-202608-0001'],
            [
                'supplier_id' => $vendorProfiles['PT Mitra Solusi Teknologi']['partner']->id,
                'pr_id' => $pr1->id,
                'ship_to' => 'Head Office HQ, Level 8, Jakarta',
                'bill_to' => 'PT Nusaevo ERP Finance Dept',
                'currency_code' => 'IDR',
                'incoterms' => 'DDP',
                'payment_terms_days' => 30,
                'status' => PurOrderHdr::STATUS_PARTIALLY_RECEIVED,
                'revision_no' => 1,
                'subtotal' => 45000000.00,
                'tax_amount' => 4950000.00,
                'total_amount' => 49950000.00,
                'expected_delivery_date' => now()->addDays(5)->toDateString(),
                'ack_status' => PurOrderHdr::ACK_ACCEPTED,
                'created_by' => $admin->id,
            ]
        );
        $po1->lines()->delete();
        $po1Line1 = $po1->lines()->create([
            'line_no' => 1,
            'catalog_item_id' => $catalogMap['IT-MON-4K']->id,
            'description' => 'Ultra-wide 34-inch Professional Monitor',
            'qty_ordered' => 5,
            'qty_received' => 5,
            'unit_price' => 6200000.00,
            'tax_amount' => 3410000.00,
            'expected_delivery_date' => now()->addDays(3)->toDateString(),
            'category_id' => $categories['IT & Hardware Equipment']->id,
            'local_content_pct' => 40.0,
        ]);
        $po1Line2 = $po1->lines()->create([
            'line_no' => 2,
            'catalog_item_id' => $catalogMap['IT-SRV-CLOUD']->id,
            'description' => 'Dedicated Cloud Compute Node (8 vCPU, 32GB RAM)',
            'qty_ordered' => 4,
            'qty_received' => 0,
            'unit_price' => 3500000.00,
            'tax_amount' => 1540000.00,
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
            'category_id' => $categories['Cloud & Software Subscriptions']->id,
            'local_content_pct' => 60.0,
        ]);
        $po1->revisions()->delete();
        $po1->revisions()->create([
            'revision_no' => 1,
            'snapshot' => [
                'po_no' => 'PO-202608-0001',
                'total_amount' => 49950000.00,
                'lines_count' => 2,
            ],
            'revised_by' => $admin->id,
            'revised_at' => now(),
        ]);

        $po2 = PurOrderHdr::query()->updateOrCreate(
            ['po_no' => 'PO-202608-0002'],
            [
                'supplier_id' => $vendorProfiles['CV Sumber Kertas Nusantara']['partner']->id,
                'pr_id' => $pr2->id,
                'ship_to' => 'Head Office HQ, Level 8, Jakarta',
                'bill_to' => 'PT Nusaevo ERP Finance Dept',
                'currency_code' => 'IDR',
                'payment_terms_days' => 14,
                'status' => PurOrderHdr::STATUS_SENT,
                'revision_no' => 1,
                'subtotal' => 12250000.00,
                'tax_amount' => 1347500.00,
                'total_amount' => 13597500.00,
                'expected_delivery_date' => now()->addDays(3)->toDateString(),
                'ack_status' => PurOrderHdr::ACK_ACCEPTED,
                'created_by' => $admin->id,
            ]
        );
        $po2->lines()->delete();
        $po2->lines()->create([
            'line_no' => 1,
            'catalog_item_id' => $catalogMap['OFF-PPR-A4']->id,
            'description' => 'A4 80gsm High Performance Paper (Box / 5 Reams)',
            'qty_ordered' => 50,
            'qty_received' => 0,
            'unit_price' => 245000.00,
            'tax_amount' => 1347500.00,
            'expected_delivery_date' => now()->addDays(3)->toDateString(),
            'category_id' => $categories['Office Supplies & Stationery']->id,
            'local_content_pct' => 90.0,
        ]);
        $po2->revisions()->delete();
        $po2->revisions()->create([
            'revision_no' => 1,
            'snapshot' => [
                'po_no' => 'PO-202608-0002',
                'total_amount' => 13597500.00,
                'lines_count' => 1,
            ],
            'revised_by' => $admin->id,
            'revised_at' => now(),
        ]);

        $po3 = PurOrderHdr::query()->updateOrCreate(
            ['po_no' => 'PO-202608-0003'],
            [
                'supplier_id' => $vendorProfiles['PT Prima Logistik Ekpres']['partner']->id,
                'pr_id' => $pr3->id,
                'ship_to' => 'Surabaya Depot Warehouse',
                'bill_to' => 'PT Nusaevo ERP Finance Dept',
                'currency_code' => 'IDR',
                'payment_terms_days' => 45,
                'status' => PurOrderHdr::STATUS_DRAFT,
                'revision_no' => 1,
                'subtotal' => 17000000.00,
                'tax_amount' => 1870000.00,
                'total_amount' => 18870000.00,
                'expected_delivery_date' => now()->addDays(14)->toDateString(),
                'created_by' => $admin->id,
            ]
        );
        $po3->lines()->delete();
        $po3->lines()->create([
            'line_no' => 1,
            'catalog_item_id' => $catalogMap['LOG-CARGO-CONTAINER']->id,
            'description' => 'Domestic Inter-Island Cargo Freight (Per 20ft TEU)',
            'qty_ordered' => 2,
            'qty_received' => 0,
            'unit_price' => 8500000.00,
            'tax_amount' => 1870000.00,
            'expected_delivery_date' => now()->addDays(14)->toDateString(),
            'category_id' => $categories['Logistics & Facility Operations']->id,
            'local_content_pct' => 100.0,
        ]);

        // 8. Goods Receipts (GR)
        $gr1 = PurReceiptHdr::query()->updateOrCreate(
            ['gr_no' => 'GR-202608-0001'],
            [
                'po_id' => $po1->id,
                'receiver_id' => $admin->id,
                'received_at' => now()->subDay(),
                'status' => PurReceiptHdr::STATUS_POSTED,
                'discrepancy_notes' => null,
            ]
        );
        $gr1->lines()->delete();
        $gr1->lines()->create([
            'po_line_id' => $po1Line1->id,
            'quantity_received' => 5,
            'unit_cost' => 6200000.00,
            'condition_notes' => 'All 5 monitors received in pristine condition and operational.',
            'over_receipt_flag' => false,
        ]);

        // 9. Vendor Invoices (Three-Way Match)
        $inv1 = PurInvoiceHdr::query()->updateOrCreate(
            [
                'supplier_id' => $vendorProfiles['PT Mitra Solusi Teknologi']['partner']->id,
                'supplier_invoice_no' => 'INV-MST-2026-0881',
            ],
            [
                'po_id' => $po1->id,
                'supplier_invoice_date' => now()->subDay()->toDateString(),
                'currency_code' => 'IDR',
                'amount' => 34410000.00,
                'submission_channel' => 'manual',
                'match_status' => PurInvoiceHdr::MATCH_MATCHED,
                'status' => PurInvoiceHdr::STATUS_CAPTURED,
                'created_by' => $admin->id,
            ]
        );
        $inv1->lines()->delete();
        $inv1->lines()->create([
            'po_line_id' => $po1Line1->id,
            'qty' => 5,
            'unit_price' => 6200000.00,
            'line_amount' => 31000000.00,
        ]);
        $inv1->matches()->delete();
        $inv1->matches()->create([
            'po_line_id' => $po1Line1->id,
            'po_qty' => 5,
            'po_price' => 6200000.00,
            'gr_qty' => 5,
            'invoice_qty' => 5,
            'invoice_price' => 6200000.00,
            'qty_variance_pct' => 0.00,
            'price_variance_pct' => 0.00,
            'within_tolerance' => true,
        ]);

        // 10. Contracts
        PurContractHdr::query()->updateOrCreate(
            [
                'supplier_id' => $vendorProfiles['PT Mitra Solusi Teknologi']['partner']->id,
                'title' => 'Master IT Infrastructure & Cloud Hosting Retainer 2026',
            ],
            [
                'type' => PurContractHdr::TYPE_FRAMEWORK,
                'value' => 180000000.00,
                'currency_code' => 'IDR',
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'auto_renew' => true,
                'notice_period_days' => 30,
                'status' => PurContractHdr::STATUS_ACTIVE,
                'created_by' => $admin->id,
            ]
        );

        // 11. Sourcing (RFX / RFQ)
        $rfx1 = PurRfxHdr::query()->updateOrCreate(
            ['rfx_no' => 'RFQ-202608-0001'],
            [
                'type' => PurRfxHdr::TYPE_RFQ,
                'pr_id' => $pr2->id,
                'due_date' => now()->addDays(14)->toDateString(),
                'status' => PurRfxHdr::STATUS_RESPONSES_OPEN,
                'created_by' => $admin->id,
            ]
        );
        $rfx1->lines()->delete();
        $rfx1->lines()->create([
            'line_no' => 1,
            'description' => 'High Performance Office Stationery & A4 Paper 80gsm Bulk Order (100 Boxes)',
            'qty' => 100,
        ]);
        $rfx1->invitations()->delete();
        $rfx1->invitations()->create([
            'supplier_id' => $vendorProfiles['CV Sumber Kertas Nusantara']['partner']->id,
            'invited_at' => now()->subDays(2),
        ]);

        // 12. Exceptions
        PurException::query()->updateOrCreate(
            [
                'exception_type' => PurException::TYPE_PRICE_VARIANCE,
                'subject_type' => 'App\Modules\Purchase\Models\PurOrderHdr',
                'subject_id' => $po2->id,
            ],
            [
                'summary' => 'Price variance of 2.1% flagged on A4 paper ream order compared to last quarter average.',
                'status' => PurException::STATUS_OPEN,
            ]
        );
    }
}
