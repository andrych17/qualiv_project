<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAdminUser;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralInvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTenantDatabaseIfExists('900');
        Tenant::query()->whereKey('900')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropTenantDatabaseIfExists('900');
        Tenant::query()->whereKey('900')->delete();

        parent::tearDown();
    }

    public function test_generating_an_invoice_snapshots_the_plan_price(): void
    {
        $admin = CentralAdminUser::query()->updateOrCreate(
            ['email' => 'simon@nusaevo.com'],
            ['name' => 'Simon', 'password' => 'password'],
        );

        $tenant = Tenant::create(['id' => '900', 'name' => 'Snapshot Co', 'plan' => 'legal']);

        CentralPlan::query()->updateOrCreate(
            ['code' => 'legal'],
            ['name' => 'Legal', 'price_monthly' => 1500000],
        );

        $this->actingAs($admin, 'central_admin')
            ->post('/central/invoices', [
                'tenant_id' => $tenant->getKey(),
                'plan_code' => 'legal',
                'billing_period_start' => now()->startOfMonth()->toDateString(),
                'billing_period_end' => now()->endOfMonth()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
            ])
            ->assertRedirect(route('central.invoices.index'));

        $this->assertDatabaseHas('central_invoices', [
            'tenant_id' => '900',
            'plan_code' => 'legal',
            'status' => 'issued',
            'amount_total' => 1500000,
        ]);

        $this->assertDatabaseHas('central_invoice_lines', [
            'description' => 'Legal plan fee',
            'amount' => 1500000,
        ]);

        // Later plan price changes never rewrite an already-issued invoice.
        CentralPlan::query()->where('code', 'legal')->update(['price_monthly' => 9999999]);
        $this->assertDatabaseHas('central_invoices', ['tenant_id' => '900', 'amount_total' => 1500000]);
    }
}
