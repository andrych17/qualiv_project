<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Models\PurReceiptHdr;
use App\Modules\Purchase\Services\GoodsReceiptService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseGoodsReceiptTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_record_full_goods_receipt_and_po_transitions_to_received(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $poId = null;
        $poLineId = null;

        $tenant->run(function () use (&$poId, &$poLineId) {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Logistics Global', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    [
                        'description' => 'Industrial Safety Helmets',
                        'qty_ordered' => 50,
                        'unit_price' => 75000,
                    ],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);
            $poService->sendToSupplier($po, $admin->id);

            $poId = $po->id;
            $poLineId = $po->lines()->first()->id;
        });

        // Visit GR Index & Create
        $this->get('/purchase/receipts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Receipts/Index'));

        $this->get("/purchase/receipts/create?po_id={$poId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Receipts/Create'));

        // Post Goods Receipt
        $response = $this->post('/purchase/receipts', [
            'po_id' => $poId,
            'received_at' => now()->toDateTimeString(),
            'discrepancy_notes' => 'All cartons inspected, no damage.',
            'lines' => [
                [
                    'po_line_id' => $poLineId,
                    'quantity_received' => 50,
                    'unit_cost' => 75000,
                    'condition_notes' => 'Complete and intact',
                ],
            ],
        ]);

        $response->assertRedirect();

        $tenant->run(function () use ($poId) {
            $gr = PurReceiptHdr::where('po_id', $poId)->first();
            $this->assertNotNull($gr);
            $this->assertStringStartsWith('GR-', $gr->gr_no);
            $this->assertSame(PurReceiptHdr::STATUS_POSTED, $gr->status);
            $this->assertCount(1, $gr->lines);
            $this->assertEquals(50, (float) $gr->lines->first()->quantity_received);
            $this->assertFalse($gr->lines->first()->over_receipt_flag);

            $po = PurOrderHdr::find($poId);
            $this->assertSame(PurOrderHdr::STATUS_RECEIVED, $po->status);
            $this->assertEquals(50, (float) $po->lines->first()->qty_received);
        });
    }

    public function test_partial_goods_receipt_transitions_po_to_partially_received(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'Supplier Alpha', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Office Chairs', 'qty_ordered' => 20, 'unit_price' => 500000],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);
            $poService->sendToSupplier($po, $admin->id);

            $poLine = $po->lines()->first();
            $grService = app(GoodsReceiptService::class);

            // First partial receipt: 8 of 20
            $gr1 = $grService->create([
                'po_id' => $po->id,
                'lines' => [
                    ['po_line_id' => $poLine->id, 'quantity_received' => 8],
                ],
            ], $admin->id);

            $po->refresh();
            $this->assertSame(PurOrderHdr::STATUS_PARTIALLY_RECEIVED, $po->status);
            $this->assertEquals(8, (float) $po->lines->first()->qty_received);

            // Second receipt: remaining 12 of 20
            $gr2 = $grService->create([
                'po_id' => $po->id,
                'lines' => [
                    ['po_line_id' => $poLine->id, 'quantity_received' => 12],
                ],
            ], $admin->id);

            $po->refresh();
            $this->assertSame(PurOrderHdr::STATUS_RECEIVED, $po->status);
            $this->assertEquals(20, (float) $po->lines->first()->qty_received);
            $this->assertCount(2, $po->receipts);
        });
    }

    public function test_over_receipt_is_flagged_with_warning(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'Supplier Beta', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Packaging Boxes', 'qty_ordered' => 100, 'unit_price' => 5000],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);
            $poService->sendToSupplier($po, $admin->id);

            $poLine = $po->lines()->first();
            $grService = app(GoodsReceiptService::class);

            // Over-receipt: 110 received vs 100 ordered
            $gr = $grService->create([
                'po_id' => $po->id,
                'lines' => [
                    ['po_line_id' => $poLine->id, 'quantity_received' => 110],
                ],
            ], $admin->id);

            $this->assertTrue($gr->lines->first()->over_receipt_flag);
            $po->refresh();
            $this->assertSame(PurOrderHdr::STATUS_RECEIVED, $po->status);
            $this->assertEquals(110, (float) $po->lines->first()->qty_received);
        });
    }

    public function test_goods_receipt_cannot_be_recorded_for_draft_po(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'Supplier Gamma', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Unapproved Item', 'qty_ordered' => 10, 'unit_price' => 10000],
                ],
            ], $admin->id);

            $poLine = $po->lines()->first();
            $grService = app(GoodsReceiptService::class);

            $this->expectException(ValidationException::class);
            $grService->create([
                'po_id' => $po->id,
                'lines' => [
                    ['po_line_id' => $poLine->id, 'quantity_received' => 10],
                ],
            ], $admin->id);
        });
    }
}
