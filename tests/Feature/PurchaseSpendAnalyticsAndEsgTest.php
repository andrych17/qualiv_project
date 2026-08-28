<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\CostCenter;
use App\Modules\Purchase\Models\PurBudget;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\PurContractHdr;
use App\Modules\Purchase\Models\PurVendorDocument;
use App\Modules\Purchase\Models\VendorProfile;
use App\Modules\Purchase\Services\EsgComplianceService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\SpendAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseSpendAnalyticsAndEsgTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_spend_analytics_computes_kpis_and_breakdowns_accurately(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);

            $vendor1 = Partner::create(['name' => 'PT Baja Perkasa', 'type' => 'company', 'is_active' => true]);
            $vendor1->roles()->create(['role_type_id' => $roleType->id]);

            $vendor2 = Partner::create(['name' => 'CV Sumber Makmur', 'type' => 'company', 'is_active' => true]);
            $vendor2->roles()->create(['role_type_id' => $roleType->id]);

            $catDirect = Category::create([
                'name' => 'Raw Materials',
                'kind' => 'direct',
                'capex_opex' => 'opex',
                'is_active' => true,
            ]);

            $catCapex = Category::create([
                'name' => 'Heavy Machinery',
                'kind' => 'direct',
                'capex_opex' => 'capex',
                'is_active' => true,
            ]);

            $catIndirect = Category::create([
                'name' => 'Office Supplies',
                'kind' => 'indirect',
                'capex_opex' => 'opex',
                'is_active' => true,
            ]);

            $costCenter = CostCenter::create([
                'code' => 'CC-OPS',
                'name' => 'Operations Division',
                'is_active' => true,
            ]);

            $budget = PurBudget::create([
                'period' => '2026',
                'cost_center_id' => $costCenter->id,
                'category_id' => $catDirect->id,
                'budget_amount' => 500000000,
                'committed_amount' => 0,
                'actual_amount' => 0,
            ]);

            $catalogItem = PurCatalogItem::create([
                'item_code' => 'CAT-STEEL-01',
                'description' => 'Structural Steel Beam',
                'category_id' => $catDirect->id,
                'preferred_supplier_id' => $vendor1->id,
                'negotiated_price' => 1000000,
                'unit' => 'pcs',
                'is_active' => true,
            ]);

            $poService = app(PurchaseOrderService::class);

            // PO 1: Vendor 1 - On-catalog & direct opex (10 x 1,000,000 = 10,000,000)
            $po1 = $poService->create([
                'supplier_id' => $vendor1->id,
                'lines' => [
                    [
                        'catalog_item_id' => $catalogItem->id,
                        'category_id' => $catDirect->id,
                        'description' => 'Structural Steel Beam',
                        'qty_ordered' => 10,
                        'unit_price' => 1000000,
                        'local_content_pct' => 55.0,
                    ],
                ],
            ], $admin->id);
            $poService->submit($po1, $admin->id);
            $poService->approve($po1, $admin->id);

            // PO 2: Vendor 1 - Direct CAPEX (1 x 90,000,000 = 90,000,000)
            $po2 = $poService->create([
                'supplier_id' => $vendor1->id,
                'lines' => [
                    [
                        'category_id' => $catCapex->id,
                        'description' => 'CNC Milling Lathe',
                        'qty_ordered' => 1,
                        'unit_price' => 90000000,
                        'local_content_pct' => 45.0,
                    ],
                ],
            ], $admin->id);
            $poService->submit($po2, $admin->id);
            $poService->approve($po2, $admin->id);

            // PO 3: Vendor 2 - Off-catalog & indirect opex (5 x 2,000,000 = 10,000,000)
            $po3 = $poService->create([
                'supplier_id' => $vendor2->id,
                'lines' => [
                    [
                        'category_id' => $catIndirect->id,
                        'description' => 'Office Ergonomic Chairs',
                        'qty_ordered' => 5,
                        'unit_price' => 2000000,
                        'local_content_pct' => 20.0,
                    ],
                ],
            ], $admin->id);
            $poService->submit($po3, $admin->id);
            $poService->approve($po3, $admin->id);

            // Active master contract for Vendor 1
            $contract = PurContractHdr::create([
                'supplier_id' => $vendor1->id,
                'contract_no' => 'CNT-2026-001',
                'title' => 'Steel & Equipment Master Agreement',
                'type' => 'framework',
                'value' => 200000000, // 200m ceiling
                'start_date' => now()->subMonth(),
                'end_date' => now()->addMonths(11),
                'status' => PurContractHdr::STATUS_ACTIVE,
            ]);

            $analyticsService = app(SpendAnalyticsService::class);
            $analytics = $analyticsService->getSpendAnalytics();

            // Total spend = 10m + 90m + 10m = 110,000,000
            $this->assertEquals(110000000, $analytics['kpis']['total_spend']);
            $this->assertSame(3, $analytics['kpis']['pos_count']);

            // Direct spend = 10m + 90m = 100m (90.9%)
            $this->assertEquals(100000000, $analytics['kpis']['direct_spend']);
            $this->assertEquals(90.9, $analytics['kpis']['direct_spend_pct']);

            // Indirect spend = 10m (9.1%)
            $this->assertEquals(10000000, $analytics['kpis']['indirect_spend']);
            $this->assertEquals(9.1, $analytics['kpis']['indirect_spend_pct']);

            // Capex spend = 90m (81.8%), Opex = 20m (18.2%)
            $this->assertEquals(90000000, $analytics['kpis']['capex_spend']);
            $this->assertEquals(20000000, $analytics['kpis']['opex_spend']);

            // On-catalog = 10m (9.1%), Off-catalog = 100m (90.9%)
            $this->assertEquals(10000000, $analytics['kpis']['on_catalog_spend']);
            $this->assertEquals(100000000, $analytics['kpis']['off_catalog_spend']);

            // Supplier concentration: Vendor 1 has 100m/110m = 90.91% -> High concentration risk flag true!
            $this->assertTrue($analytics['supplier_concentration']['high_risk_flag']);
            $this->assertSame('PT Baja Perkasa', $analytics['supplier_concentration']['top_supplier_name']);
            $this->assertEquals(90.91, $analytics['supplier_concentration']['top_supplier_share_pct']);

            // Contract utilization: 100m consumed out of 200m ceiling = 50.0%
            $this->assertNotEmpty($analytics['contract_utilization']);
            $this->assertEquals(50.0, $analytics['contract_utilization'][0]['utilization_pct']);
            $this->assertSame('normal', $analytics['contract_utilization'][0]['health_status']);
        });
    }

    public function test_esg_compliance_computes_weighted_and_unweighted_tkdn_and_tiers(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);

            $vendorHigh = Partner::create(['name' => 'PT Fabrikasi Domestik', 'type' => 'company', 'is_active' => true]);
            $vendorHigh->roles()->create(['role_type_id' => $roleType->id]);

            $vendorImport = Partner::create(['name' => 'PT Global Importir', 'type' => 'company', 'is_active' => true]);
            $vendorImport->roles()->create(['role_type_id' => $roleType->id]);

            $cat = Category::create([
                'name' => 'Production Parts',
                'kind' => 'direct',
                'is_active' => true,
            ]);

            $poService = app(PurchaseOrderService::class);

            // PO 1: High TKDN (60%) on 40,000,000 -> Local value = 24,000,000
            $po1 = $poService->create([
                'supplier_id' => $vendorHigh->id,
                'lines' => [
                    [
                        'category_id' => $cat->id,
                        'description' => 'Locally Manufactured Flanges',
                        'qty_ordered' => 40,
                        'unit_price' => 1000000,
                        'local_content_pct' => 60.0,
                    ],
                ],
            ], $admin->id);
            $poService->submit($po1, $admin->id);
            $poService->approve($po1, $admin->id);

            // PO 2: Low TKDN (10%) on 10,000,000 -> Local value = 1,000,000
            $po2 = $poService->create([
                'supplier_id' => $vendorImport->id,
                'lines' => [
                    [
                        'category_id' => $cat->id,
                        'description' => 'Imported Electronic Sensors',
                        'qty_ordered' => 10,
                        'unit_price' => 1000000,
                        'local_content_pct' => 10.0,
                    ],
                ],
            ], $admin->id);
            $poService->submit($po2, $admin->id);
            $poService->approve($po2, $admin->id);

            // Vendor profiles with compliance docs
            $profile1 = VendorProfile::create([
                'partner_id' => $vendorHigh->id,
                'tax_registration_no' => '01.234.567.8-001.000',
                'onboarding_status' => 'approved',
            ]);

            // Valid document
            PurVendorDocument::create([
                'vendor_profile_id' => $profile1->id,
                'doc_type' => 'license',
                'title' => 'Surat Izin Usaha Perdagangan (SIUP)',
                'expiry_date' => now()->addMonths(6),
                'status' => 'valid',
            ]);

            $profile2 = VendorProfile::create([
                'partner_id' => $vendorImport->id,
                'tax_registration_no' => '02.345.678.9-002.000',
                'onboarding_status' => 'approved',
            ]);

            // Expiring soon document (15 days remaining)
            PurVendorDocument::create([
                'vendor_profile_id' => $profile2->id,
                'doc_type' => 'insurance',
                'title' => 'Certificate of Insurance (COI)',
                'expiry_date' => now()->addDays(15),
                'status' => 'expiring_soon',
            ]);

            $esgService = app(EsgComplianceService::class);
            $report = $esgService->getEsgComplianceReport();

            // Total Spend = 50,000,000
            // Weighted TKDN = ((40m * 60) + (10m * 10)) / 50m = (2400m + 100m) / 50m = 2500m / 50m = 50.0%
            $this->assertEquals(50.0, $report['tkdn_summary']['weighted_average_pct']);

            // Unweighted TKDN = (60 + 10) / 2 = 35.0%
            $this->assertEquals(35.0, $report['tkdn_summary']['unweighted_average_pct']);

            // Total local content value = 24m + 1m = 25,000,000
            $this->assertEquals(25000000, $report['tkdn_summary']['total_local_content_value']);
            $this->assertTrue($report['tkdn_summary']['compliant_target_met']);

            // Tier distribution: 1 high tier (40m), 1 low tier (10m)
            $highTier = collect($report['tier_distribution'])->firstWhere('key', 'high');
            $lowTier = collect($report['tier_distribution'])->firstWhere('key', 'low');
            $this->assertSame(1, $highTier['count']);
            $this->assertEquals(40000000, $highTier['spend']);
            $this->assertSame(1, $lowTier['count']);
            $this->assertEquals(10000000, $lowTier['spend']);

            // Vendor compliance docs
            $this->assertSame(1, $report['vendor_compliance_summary']['doc_valid_count']);
            $this->assertSame(1, $report['vendor_compliance_summary']['doc_expiring_soon_count']);
            $this->assertNotEmpty($report['expiring_documents']);
            $this->assertSame('PT Global Importir', $report['expiring_documents'][0]['vendor_name']);
        });
    }

    public function test_analytics_spend_and_esg_http_endpoints_render_inertia_pages(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        // 1. GET /purchase/analytics redirects to /purchase/analytics/spend
        $this->get('/purchase/analytics')
            ->assertRedirect(route('purchase.analytics.spend'));

        // 2. GET /purchase/analytics/spend renders Inertia Spend page
        $this->get('/purchase/analytics/spend')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Analytics/Spend')
                ->has('kpis')
                ->has('spend_by_supplier')
                ->has('spend_by_category')
                ->has('spend_by_cost_center')
                ->has('contract_utilization')
            );

        // 3. GET /purchase/analytics/esg renders Inertia Esg page
        $this->get('/purchase/analytics/esg')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Analytics/Esg')
                ->has('tkdn_summary')
                ->has('tier_distribution')
                ->has('tkdn_by_category')
                ->has('tkdn_by_supplier')
                ->has('vendor_compliance_summary')
                ->has('expiring_documents')
            );

        // 4. Query filters pass correctly
        $this->get('/purchase/analytics/spend?date_range=this_month')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('active_filters.date_range', 'this_month'));
    }
}
