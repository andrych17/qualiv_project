<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralDunningPolicy;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPayment;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenantDatabaseIfExists('806');
        Tenant::query()->whereKey('806')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('806');
        Tenant::query()->whereKey('806')->delete();

        parent::tearDown();
    }

    public function test_dashboard_surfaces_pending_payments_and_overdue_invoices(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        CentralDunningPolicy::query()->updateOrCreate(
            ['scope_type' => 'platform_default', 'scope_id' => null],
            ['reminder_offsets_days' => [-7, -3, -1, 3, 7], 'cutoff_days_after_due' => 14],
        );

        CentralPlan::query()->updateOrCreate(['code' => 'starter'], ['name' => 'Starter', 'price_monthly' => 500000]);

        $tenant = Tenant::create(['id' => '806', 'name' => 'Dashboard Co', 'plan' => 'starter']);

        $overdueInvoice = CentralInvoice::query()->create([
            'tenant_id' => '806',
            'plan_code' => 'starter',
            'billing_period_start' => now()->subMonth()->startOfMonth(),
            'billing_period_end' => now()->subMonth()->endOfMonth(),
            'status' => 'issued',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => today()->subDays(3),
            'issued_at' => now()->subMonth(),
        ]);

        CentralPayment::query()->create([
            'invoice_id' => $overdueInvoice->id,
            'tenant_id' => '806',
            'amount' => 500000,
            'method' => 'bank_transfer',
            'paid_at' => now(),
            'status' => 'pending_review',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin, 'central_admin')
            ->get('/central')
            ->assertInertia(fn ($page) => $page
                ->component('Central/Dashboard')
                ->where('summary.payments_pending_review', 1)
                ->has('overdueInvoices', 1)
                ->has('pendingPayments', 1));

        $this->actingAs($admin, 'central_admin')
            ->getJson("/central/tenants/{$tenant->getKey()}/audit-log")
            ->assertOk()
            ->assertJsonPath('tenant.id', '806');
    }
}
