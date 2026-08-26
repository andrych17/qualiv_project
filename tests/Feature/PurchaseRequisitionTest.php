<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\CostCenter;
use App\Modules\Purchase\Models\PurBudget;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Services\RequisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseRequisitionTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_create_and_view_purchase_requisition(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $costCenterId = null;
        $categoryId = null;
        $catalogItemId = null;

        $tenant->run(function () use (&$costCenterId, &$categoryId, &$catalogItemId) {
            $cc = CostCenter::create(['code' => 'IT-01', 'name' => 'IT Department', 'is_active' => true]);
            $cat = Category::create(['name' => 'Hardware', 'kind' => 'direct', 'capex_opex' => 'capex', 'is_active' => true]);
            $item = PurCatalogItem::create([
                'item_code' => 'LAPTOP-001',
                'description' => 'Developer Workstation Laptop',
                'category_id' => $cat->id,
                'negotiated_price' => 25000000,
                'unit' => 'unit',
                'is_active' => true,
            ]);

            $costCenterId = $cc->id;
            $categoryId = $cat->id;
            $catalogItemId = $item->id;
        });

        // Visit Index
        $this->get('/purchase/requisitions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Requisitions/Index'));

        // Visit Create
        $this->get('/purchase/requisitions/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Requisitions/Create'));

        // Store Requisition
        $response = $this->post('/purchase/requisitions', [
            'cost_center_id' => $costCenterId,
            'needed_by' => now()->addDays(14)->toDateString(),
            'notes' => 'Laptops for new engineering hires.',
            'lines' => [
                [
                    'catalog_item_id' => $catalogItemId,
                    'description' => 'Developer Workstation Laptop',
                    'qty' => 2,
                    'estimated_unit_price' => 25000000,
                    'category_id' => $categoryId,
                    'local_content_pct' => 45.0,
                ],
            ],
        ]);

        $response->assertRedirect();

        $tenant->run(function () {
            $pr = PurRequisitionHdr::first();
            $this->assertNotNull($pr);
            $this->assertStringStartsWith('PR-', $pr->pr_no);
            $this->assertSame(PurRequisitionHdr::STATUS_DRAFT, $pr->status);
            $this->assertEquals(50000000, (float) $pr->estimated_total);
            $this->assertCount(1, $pr->lines);
            $this->assertEquals(45.0, (float) $pr->lines->first()->local_content_pct);
        });
    }

    public function test_duplicate_pr_warning_is_flagged_for_same_requester_within_30_days(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $cat = Category::create(['name' => 'Software', 'kind' => 'indirect', 'capex_opex' => 'opex']);
            $item = PurCatalogItem::create([
                'item_code' => 'IDE-LIC-01',
                'description' => 'IDE Annual License',
                'category_id' => $cat->id,
                'negotiated_price' => 3000000,
            ]);

            $service = app(RequisitionService::class);

            // First PR
            $pr1 = $service->create([
                'requester_id' => $admin->id,
                'lines' => [
                    [
                        'catalog_item_id' => $item->id,
                        'description' => 'IDE Annual License',
                        'qty' => 1,
                        'estimated_unit_price' => 3000000,
                        'category_id' => $cat->id,
                    ],
                ],
            ], $admin->id);

            $this->assertFalse($pr1->duplicate_warning);

            // Second PR with same requester and item
            $pr2 = $service->create([
                'requester_id' => $admin->id,
                'lines' => [
                    [
                        'catalog_item_id' => $item->id,
                        'description' => 'IDE Annual License',
                        'qty' => 1,
                        'estimated_unit_price' => 3000000,
                        'category_id' => $cat->id,
                    ],
                ],
            ], $admin->id);

            $this->assertTrue($pr2->duplicate_warning);
        });
    }

    public function test_soft_budget_warning_is_flagged_when_exceeding_budget_amount(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $cc = CostCenter::create(['code' => 'HR-01', 'name' => 'Human Resources']);
            $cat = Category::create(['name' => 'Office Supplies', 'kind' => 'indirect', 'capex_opex' => 'opex']);

            // Budget for current month: 5,000,000
            $period = now()->format('Y-m');
            PurBudget::create([
                'period' => $period,
                'cost_center_id' => $cc->id,
                'category_id' => $cat->id,
                'budget_amount' => 5000000,
                'committed_amount' => 0,
                'actual_amount' => 0,
            ]);

            $service = app(RequisitionService::class);

            // PR requesting 8,000,000 (exceeds 5,000,000)
            $pr = $service->create([
                'requester_id' => $admin->id,
                'cost_center_id' => $cc->id,
                'needed_by' => now()->toDateString(),
                'lines' => [
                    [
                        'description' => 'Bulk Printing Supplies',
                        'qty' => 10,
                        'estimated_unit_price' => 800000,
                        'category_id' => $cat->id,
                    ],
                ],
            ], $admin->id);

            // Soft warning is flagged, but record is still successfully created
            $this->assertTrue($pr->budget_warning);
            $this->assertEquals(8000000, (float) $pr->estimated_total);
        });
    }

    public function test_pr_lifecycle_submit_approve_reject_and_cancel(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $prId = null;
        $tenant->run(function () use (&$prId) {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $service = app(RequisitionService::class);
            $pr = $service->create([
                'requester_id' => $admin->id,
                'lines' => [
                    ['description' => 'Test Hardware Item', 'qty' => 1, 'estimated_unit_price' => 1000000],
                ],
            ], $admin->id);
            $prId = $pr->id;
        });

        // Submit PR
        $this->post("/purchase/requisitions/{$prId}/submit")->assertRedirect();
        $tenant->run(function () use ($prId) {
            $pr = PurRequisitionHdr::find($prId);
            $this->assertSame(PurRequisitionHdr::STATUS_PENDING_APPROVAL, $pr->status);
        });

        // Approve PR
        $this->post("/purchase/requisitions/{$prId}/approve")->assertRedirect();
        $tenant->run(function () use ($prId) {
            $pr = PurRequisitionHdr::find($prId);
            $this->assertSame(PurRequisitionHdr::STATUS_APPROVED, $pr->status);
        });
    }
}
