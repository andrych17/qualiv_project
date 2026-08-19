<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralTenantAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class GenerateCentralInvoicesCommandTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenantDatabaseIfExists('905');
        Tenant::query()->whereKey('905')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('905');
        Tenant::query()->whereKey('905')->delete();

        parent::tearDown();
    }

    public function test_command_generates_one_itemized_invoice_per_tenant_and_is_idempotent(): void
    {
        CentralPlan::query()->updateOrCreate(['code' => 'starter'], ['name' => 'Starter', 'price_monthly' => 500000]);
        $tenant = Tenant::create(['id' => '905', 'name' => 'Recurring Co', 'plan' => 'starter']);

        CentralTenantAddon::query()->create([
            'tenant_id' => $tenant->getKey(),
            'module_code' => 'PERF',
            'added_at' => now(),
            'price_override' => 100000,
            'status' => 'active',
        ]);

        $this->artisan('central:generate-invoices')->assertSuccessful();

        $this->assertDatabaseHas('central_invoices', [
            'tenant_id' => '905',
            'plan_code' => 'starter',
            'amount_total' => 600000,
        ]);
        $this->assertDatabaseHas('central_invoice_lines', ['description' => 'Add-on: PERF', 'amount' => 100000]);
        $this->assertDatabaseCount('central_invoices', 1);

        // Re-running for the same period must not duplicate the invoice.
        $this->artisan('central:generate-invoices')->assertSuccessful();
        $this->assertDatabaseCount('central_invoices', 1);
    }

    public function test_annual_plan_uses_annual_price_and_anniversary_period(): void
    {
        CentralPlan::query()->updateOrCreate(
            ['code' => 'legal-pro'],
            ['name' => 'Legal Pro', 'price_monthly' => 500000, 'price_annual' => 5000000, 'billing_cycle' => 'annual'],
        );

        $created = now()->subMonths(3); // anniversary already reached this year
        $tenant = Tenant::create(['id' => '905', 'name' => 'Recurring Co', 'plan' => 'legal-pro', 'created_at' => $created]);

        $this->artisan('central:generate-invoices')->assertSuccessful();

        $expectedStart = $created->copy()->setYear(now()->year)->toDateString();
        $expectedEnd = $created->copy()->setYear(now()->year)->addYear()->subDay()->toDateString();

        $this->assertDatabaseHas('central_invoices', [
            'tenant_id' => '905',
            'plan_code' => 'legal-pro',
            'billing_period_start' => $expectedStart,
            'billing_period_end' => $expectedEnd,
            'amount_total' => 5000000,
        ]);
        $this->assertDatabaseHas('central_invoice_lines', ['description' => 'Legal Pro plan fee', 'amount' => 5000000]);
    }
}
