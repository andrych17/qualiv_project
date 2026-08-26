<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\PurException;
use App\Modules\Purchase\Models\PurInvoiceHdr;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Services\GoodsReceiptService;
use App\Modules\Purchase\Services\InvoiceMatchingService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseInvoiceThreeWayMatchTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_capture_invoice_and_three_way_match_succeeds_when_quantities_and_prices_match(): void
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
            $partner = Partner::create(['name' => 'PT Sumber Rezeki', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Industrial Centrifugal Pumps', 'qty_ordered' => 10, 'unit_price' => 5000000],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);
            $poService->sendToSupplier($po, $admin->id);

            // Record full GR
            $grService = app(GoodsReceiptService::class);
            $grService->create([
                'po_id' => $po->id,
                'lines' => [
                    ['po_line_id' => $po->lines->first()->id, 'quantity_received' => 10],
                ],
            ], $admin->id);

            $poId = $po->id;
            $poLineId = $po->lines->first()->id;
        });

        // Visit Invoices Index & Create
        $this->get('/purchase/invoices')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Invoices/Index'));

        $this->get("/purchase/invoices/create?po_id={$poId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Invoices/Create'));

        // Post Invoice
        $response = $this->post('/purchase/invoices', [
            'po_id' => $poId,
            'supplier_invoice_no' => 'INV-SR-2026-001',
            'supplier_invoice_date' => now()->toDateString(),
            'lines' => [
                [
                    'po_line_id' => $poLineId,
                    'qty' => 10,
                    'unit_price' => 5000000,
                ],
            ],
        ]);

        $response->assertRedirect();

        $tenant->run(function () use ($poId) {
            $invoice = PurInvoiceHdr::where('po_id', $poId)->first();
            $this->assertNotNull($invoice);
            $this->assertSame('INV-SR-2026-001', $invoice->supplier_invoice_no);
            $this->assertSame(PurInvoiceHdr::MATCH_MATCHED, $invoice->match_status);
            $this->assertSame(PurInvoiceHdr::STATUS_CAPTURED, $invoice->status);
            $this->assertCount(1, $invoice->matches);
            $this->assertTrue($invoice->matches->first()->within_tolerance);
            $this->assertEquals(0, (float) $invoice->matches->first()->qty_variance_pct);
            $this->assertEquals(0, (float) $invoice->matches->first()->price_variance_pct);

            // Submit and approve
            $matchingService = app(InvoiceMatchingService::class);
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $matchingService->submitForApproval($invoice, $admin->id);
            $this->assertSame(PurInvoiceHdr::STATUS_PENDING_APPROVAL, $invoice->status);

            $matchingService->approve($invoice, $admin->id);
            $this->assertContains($invoice->status, [PurInvoiceHdr::STATUS_APPROVED, PurInvoiceHdr::STATUS_SENT_TO_ACCOUNTING]);
        });
    }

    public function test_three_way_match_fails_when_invoiced_quantity_exceeds_goods_receipt(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Short Ship', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Heavy Duty Cables', 'qty_ordered' => 100, 'unit_price' => 25000],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);
            $poService->sendToSupplier($po, $admin->id);

            // GR only received 60 of 100
            $grService = app(GoodsReceiptService::class);
            $grService->create([
                'po_id' => $po->id,
                'lines' => [
                    ['po_line_id' => $po->lines->first()->id, 'quantity_received' => 60],
                ],
            ], $admin->id);

            // Supplier bills for full 100
            $matchingService = app(InvoiceMatchingService::class);
            $invoice = $matchingService->captureInvoice([
                'po_id' => $po->id,
                'supplier_invoice_no' => 'INV-SS-009',
                'lines' => [
                    ['po_line_id' => $po->lines->first()->id, 'qty' => 100, 'unit_price' => 25000],
                ],
            ], $admin->id);

            $this->assertSame(PurInvoiceHdr::MATCH_MISMATCH, $invoice->match_status);
            $this->assertFalse($invoice->matches->first()->within_tolerance);
            $this->assertGreaterThan(0, (float) $invoice->matches->first()->qty_variance_pct);

            // Verify exception logged
            $exception = PurException::where('subject_type', 'purchase.pur_invoice_hdrs')
                ->where('subject_id', $invoice->id)
                ->first();
            $this->assertNotNull($exception);
            $this->assertSame(PurException::TYPE_UNMATCHED_INVOICE, $exception->exception_type);
        });
    }

    public function test_three_way_match_fails_when_invoiced_price_differs_from_po(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Price Hike', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Industrial Solvents', 'qty_ordered' => 20, 'unit_price' => 100000],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);
            $poService->sendToSupplier($po, $admin->id);

            $grService = app(GoodsReceiptService::class);
            $grService->create([
                'po_id' => $po->id,
                'lines' => [
                    ['po_line_id' => $po->lines->first()->id, 'quantity_received' => 20],
                ],
            ], $admin->id);

            // Supplier bills unit price 120,000 instead of 100,000 (20% variance)
            $matchingService = app(InvoiceMatchingService::class);
            $invoice = $matchingService->captureInvoice([
                'po_id' => $po->id,
                'supplier_invoice_no' => 'INV-PH-101',
                'lines' => [
                    ['po_line_id' => $po->lines->first()->id, 'qty' => 20, 'unit_price' => 120000],
                ],
            ], $admin->id);

            $this->assertSame(PurInvoiceHdr::MATCH_MISMATCH, $invoice->match_status);
            $this->assertFalse($invoice->matches->first()->within_tolerance);
            $this->assertEquals(20.0, (float) $invoice->matches->first()->price_variance_pct);
        });
    }

    public function test_duplicate_invoice_number_for_same_supplier_is_blocked(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Duplicate Check', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'lines' => [
                    ['description' => 'Bearings', 'qty_ordered' => 5, 'unit_price' => 50000],
                ],
            ], $admin->id);

            $matchingService = app(InvoiceMatchingService::class);
            $matchingService->captureInvoice([
                'po_id' => $po->id,
                'supplier_invoice_no' => 'INV-DUP-01',
                'lines' => [
                    ['po_line_id' => $po->lines->first()->id, 'qty' => 5, 'unit_price' => 50000],
                ],
            ], $admin->id);

            $this->expectException(ValidationException::class);
            $matchingService->captureInvoice([
                'po_id' => $po->id,
                'supplier_invoice_no' => 'INV-DUP-01',
                'lines' => [
                    ['po_line_id' => $po->lines->first()->id, 'qty' => 5, 'unit_price' => 50000],
                ],
            ], $admin->id);
        });
    }
}
