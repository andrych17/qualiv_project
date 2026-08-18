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
}
