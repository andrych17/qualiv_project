<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Purchase\Models\PurContractHdr;
use App\Modules\Purchase\Services\ContractService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PurchaseContractTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_admin_can_view_contract_index_and_create_page(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/purchase/contracts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Contracts/Index'));

        $this->get('/purchase/contracts/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Contracts/Create'));
    }

    public function test_admin_can_create_activate_track_spend_and_renew_contract(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $partnerId = null;

        $tenant->run(function () use (&$partnerId) {
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'PT Mega Supply Indo', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);
            $partnerId = $partner->id;
        });

        // Create Contract
        $response = $this->post('/purchase/contracts', [
            'supplier_id' => $partnerId,
            'title' => 'Master Steel Supply Agreement 2026',
            'type' => 'framework',
            'value' => 500000000,
            'currency_code' => 'IDR',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'auto_renew' => true,
            'notice_period_days' => 30,
        ]);

        $contractId = null;

        $tenant->run(function () use (&$contractId) {
            $contract = PurContractHdr::where('title', 'Master Steel Supply Agreement 2026')->first();
            $this->assertNotNull($contract);
            $this->assertSame(PurContractHdr::STATUS_DRAFT, $contract->status);
            $this->assertEquals(500000000, (float) $contract->value);

            $contractId = $contract->id;
        });

        // Activate Contract
        $this->post("/purchase/contracts/{$contractId}/activate")
            ->assertRedirect();

        $tenant->run(function () use ($contractId) {
            $contract = PurContractHdr::find($contractId);
            $this->assertSame(PurContractHdr::STATUS_ACTIVE, $contract->status);
        });

        // Create PO under this supplier to test spend tracking
        $tenant->run(function () use ($partnerId, $contractId) {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $poService = app(PurchaseOrderService::class);
            $po = $poService->create([
                'supplier_id' => $partnerId,
                'lines' => [
                    ['description' => 'Structural Steel Beams', 'qty_ordered' => 10, 'unit_price' => 15000000],
                ],
            ], $admin->id);

            $contractService = app(ContractService::class);
            $contract = PurContractHdr::find($contractId);
            $spend = $contractService->calculateSpend($contract);

            $this->assertEquals(150000000, $spend); // 10 * 15M = 150M against 500M ceiling
        });

        // View Show page
        $this->get("/purchase/contracts/{$contractId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Purchase/Contracts/Show')
                ->has('contract')
                ->has('relatedOrders'));

        // Renew Contract
        $newEndDate = now()->addYears(2)->toDateString();
        $this->post("/purchase/contracts/{$contractId}/renew", [
            'end_date' => $newEndDate,
            'value' => 750000000,
        ])->assertRedirect();

        $tenant->run(function () use ($contractId, $newEndDate) {
            $contract = PurContractHdr::find($contractId);
            $this->assertSame(PurContractHdr::STATUS_RENEWED, $contract->status);
            $this->assertSame($newEndDate, $contract->end_date->toDateString());
            $this->assertEquals(750000000, (float) $contract->value);
        });
    }

    public function test_scan_expiring_contracts_flags_status(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $contractId = null;

        $tenant->run(function () use (&$contractId) {
            $admin = User::where('email', 'admin@nusaevo.com')->firstOrFail();
            $roleType = PartnerRoleType::create(['code' => 'VENDOR', 'name' => 'Vendor']);
            $partner = Partner::create(['name' => 'Supplier Quick Expire', 'type' => 'company', 'is_active' => true]);
            $partner->roles()->create(['role_type_id' => $roleType->id]);

            $contractService = app(ContractService::class);
            $contract = $contractService->create([
                'supplier_id' => $partner->id,
                'title' => 'Expiring Blanket Order',
                'type' => 'blanket',
                'start_date' => now()->subMonths(11)->toDateString(),
                'end_date' => now()->addDays(10)->toDateString(), // 10 days left (notice is 30)
                'notice_period_days' => 30,
            ], $admin->id);

            $contractService->activate($contract);
            $contractId = $contract->id;

            $flaggedCount = $contractService->scanExpiringContracts();
            $this->assertGreaterThanOrEqual(1, $flaggedCount);

            $contract->refresh();
            $this->assertSame(PurContractHdr::STATUS_EXPIRING_SOON, $contract->status);
        });
    }
}
