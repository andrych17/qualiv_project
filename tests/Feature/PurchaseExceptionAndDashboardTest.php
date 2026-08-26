<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\PurException;
use App\Modules\Purchase\Models\PurOrderHdr;
use App\Modules\Purchase\Services\ExceptionService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseExceptionAndDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_view_purchase_dashboard_and_metrics(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/purchase/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Dashboard')
                ->has('metrics')
                ->has('exceptions')
                ->has('recentPrs')
                ->has('recentPos'));
    }

    public function test_admin_can_scan_detect_and_resolve_late_delivery_exception(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $poId = null;

        $tenant->run(function () use (&$poId) {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'Supplier Express', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partner->id,
                'expected_delivery_date' => now()->subDays(5)->toDateString(), // 5 days past due
                'lines' => [
                    ['description' => 'Overdue Generator Parts', 'qty_ordered' => 2, 'unit_price' => 1500000],
                ],
            ], $admin->id);

            $poService->submit($po, $admin->id);
            $poService->approve($po, $admin->id);
            $poService->sendToSupplier($po, $admin->id);

            $poId = $po->id;
        });

        // Trigger Exception Scan
        $this->post('/purchase/exceptions/scan')
            ->assertRedirect();

        $exceptionId = null;

        $tenant->run(function () use ($poId, &$exceptionId) {
            $exception = PurException::where('subject_type', 'purchase.pur_order_hdrs')
                ->where('subject_id', $poId)
                ->first();

            $this->assertNotNull($exception);
            $this->assertSame(PurException::TYPE_LATE_DELIVERY, $exception->exception_type);
            $this->assertSame(PurException::STATUS_OPEN, $exception->status);

            $exceptionId = $exception->id;
        });

        // View Exceptions page
        $this->get('/purchase/exceptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Exceptions/Index'));

        // Resolve Exception
        $this->post("/purchase/exceptions/{$exceptionId}/resolve")
            ->assertRedirect();

        $tenant->run(function () use ($exceptionId) {
            $exception = PurException::find($exceptionId);
            $this->assertSame(PurException::STATUS_RESOLVED, $exception->status);
            $this->assertNotNull($exception->resolved_by);
            $this->assertNotNull($exception->resolved_at);
        });
    }

    public function test_admin_can_dismiss_exception(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $exceptionId = null;

        $tenant->run(function () use (&$exceptionId) {
            $service = app(ExceptionService::class);
            $exception = $service->log(
                PurException::TYPE_BUDGET_FLAG,
                'purchase.pur_requisition_hdrs',
                999,
                'Requisition PR-202608-0099 exceeds category budget soft limit.'
            );
            $exceptionId = $exception->id;
        });

        $this->post("/purchase/exceptions/{$exceptionId}/dismiss")
            ->assertRedirect();

        $tenant->run(function () use ($exceptionId) {
            $exception = PurException::find($exceptionId);
            $this->assertSame(PurException::STATUS_DISMISSED, $exception->status);
        });
    }
}
