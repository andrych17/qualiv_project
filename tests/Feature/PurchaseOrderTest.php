<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\Category;
use App\Modules\Purchase\Models\PurCatalogItem;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurOrderRevision;
use App\Modules\Purchase\Models\PurRequisitionHdr;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\RequisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_create_and_view_standalone_purchase_order(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $supplierId = null;
        $categoryId = null;

        $tenant->run(function () use (&$supplierId, &$categoryId) {
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Sumber Makmur', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $cat = Category::create(['name' => 'Raw Materials', 'kind' => 'direct', 'capex_opex' => 'opex', 'is_active' => true]);

            $supplierId = $partner->id;
            $categoryId = $cat->id;
        });

        // Visit Index
        $this->get('/purchase/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Orders/Index'));

        // Visit Create
        $this->get('/purchase/orders/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Orders/Create'));

        // Store PO
        $response = $this->post('/purchase/orders', [
            'supplier_id' => $supplierId,
            'expected_delivery_date' => now()->addDays(10)->toDateString(),
            'currency_code' => 'IDR',
            'payment_terms_days' => 30,
            'lines' => [
                [
                    'description' => 'Steel Rods 12mm',
                    'qty_ordered' => 100,
                    'unit_price' => 150000,
                    'tax_amount' => 1650000,
                    'category_id' => $categoryId,
                    'local_content_pct' => 80.0,
                ],
            ],
        ]);

        $response->assertRedirect();

        $tenant->run(function () {
            $po = PurOrderHdr::first();
            $this->assertNotNull($po);
            $this->assertStringStartsWith('PO-', $po->po_no);
            $this->assertSame(PurOrderHdr::STATUS_DRAFT, $po->status);
            $this->assertSame(1, $po->revision_no);
            $this->assertEquals(15000000, (float) $po->subtotal);
            $this->assertEquals(1650000, (float) $po->tax_amount);
            $this->assertEquals(16650000, (float) $po->total_amount);
            $this->assertCount(1, $po->lines);
        });
    }

    public function test_approved_pr_can_be_converted_to_purchase_order(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $prId = null;
        $supplierId = null;

        $tenant->run(function () use (&$prId, &$supplierId) {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Tech Supplier', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $cat = Category::create(['name' => 'Electronics', 'kind' => 'direct', 'capex_opex' => 'capex']);

            $reqService = app(RequisitionService::class);
            $pr = $reqService->create([
                'requester_id' => $admin->id,
                'lines' => [
                    [
                        'description' => 'Server Rack Cabinet',
                        'qty' => 2,
                        'estimated_unit_price' => 12000000,
                        'category_id' => $cat->id,
                    ],
                ],
            ], $admin->id);

            $reqService->submit($pr, $admin->id);
            $reqService->approve($pr, $admin->id);

            $prId = $pr->id;
            $supplierId = $partner->id;
        });

        // Convert PR to PO via endpoint
        $response = $this->post("/purchase/requisitions/{$prId}/convert-to-po", [
            'supplier_id' => $supplierId,
            'expected_delivery_date' => now()->addDays(20)->toDateString(),
        ]);

        $response->assertRedirect();

        $tenant->run(function () use ($prId, $supplierId) {
            $pr = PurRequisitionHdr::find($prId);
            $this->assertSame(PurRequisitionHdr::STATUS_CONVERTED, $pr->status);

            $po = PurOrderHdr::where('pr_id', $prId)->first();
            $this->assertNotNull($po);
            $this->assertSame($supplierId, $po->supplier_id);
            $this->assertEquals(24000000, (float) $po->total_amount);
            $this->assertCount(1, $po->lines);
            $this->assertSame('Server Rack Cabinet', $po->lines->first()->description);
        });
    }

    public function test_po_amendment_creates_revision_snapshot_after_approval(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'CV Logistik Maju', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    [
                        'description' => 'Original Item A',
                        'qty_ordered' => 10,
                        'unit_price' => 100000,
                        'tax_amount' => 0,
                    ],
                ],
            ], $admin->id);

            $this->assertSame(1, $po->revision_no);

            // Move to approved
            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);

            // Amend PO (update quantities & price)
            $amendedPo = $poService->update($po, [
                'supplier_id' => $partner->id,
                'lines' => [
                    [
                        'description' => 'Amended Item A',
                        'qty_ordered' => 15,
                        'unit_price' => 120000,
                        'tax_amount' => 0,
                    ],
                ],
            ], $admin->id);

            $this->assertSame(2, $amendedPo->revision_no);
            $this->assertEquals(1800000, (float) $amendedPo->total_amount);

            // Verify revision snapshot in database
            $revision = PurOrderRevision::where('po_id', $po->id)->first();
            $this->assertNotNull($revision);
            $this->assertSame(1, $revision->revision_no);
            $this->assertSame('Original Item A', $revision->snapshot['lines'][0]['description']);
            $this->assertEquals(10, $revision->snapshot['lines'][0]['qty_ordered']);
        });
    }

    public function test_po_send_and_acknowledgment(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'Vendor Test', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Item X', 'qty_ordered' => 5, 'unit_price' => 200000],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);

            // Send to supplier
            $poService->sendToSupplier($po, $admin->id);
            $this->assertSame(PurOrderHdr::STATUS_SENT, $po->fresh()->status);

            // Supplier accepts
            $poService->recordAcknowledgment($po, PurOrderHdr::ACK_ACCEPTED, 'Confirmed delivery in 3 days', $admin->id);
            $fresh = $po->fresh();
            $this->assertSame(PurOrderHdr::STATUS_ACKNOWLEDGED, $fresh->status);
            $this->assertSame(PurOrderHdr::ACK_ACCEPTED, $fresh->ack_status);
        });
    }

    public function test_po_cancellation_is_blocked_if_goods_are_received(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'Vendor Test 2', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Item Y', 'qty_ordered' => 10, 'unit_price' => 50000],
                ],
            ], $admin->id);

            // Simulate partial receipt of goods
            $line = $po->lines()->first();
            $line->qty_received = 4;
            $line->save();

            $this->expectException(ValidationException::class);
            $poService->cancel($po, $admin->id);
        });
    }
}
