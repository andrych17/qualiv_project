<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_tenant_user_can_view_billing_screen_and_submit_a_payment(): void
    {
        CentralPlan::query()->updateOrCreate(['code' => 'starter'], ['name' => 'Starter', 'price_monthly' => 500000]);
        $this->provisionTenant();
        Tenant::query()->whereKey('001')->update(['plan' => 'starter']);

        $invoice = CentralInvoice::query()->create([
            'tenant_id' => '001',
            'plan_code' => 'starter',
            'billing_period_start' => now()->startOfMonth(),
            'billing_period_end' => now()->endOfMonth(),
            'status' => 'issued',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => now()->addDays(14),
            'issued_at' => now(),
        ]);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/billing')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Index')
                ->where('plan.code', 'starter')
                ->has('invoices', 1));

        $this->post("/billing/invoices/{$invoice->id}/payments", [
            'amount' => 500000,
            'paid_at' => now()->toDateString(),
            'receipt' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ])->assertRedirect(route('billing.index'));

        // Logging in as a tenant user leaves the app's default DB connection pointed at the
        // tenant DB — assert against the central connection explicitly, not the runtime default.
        $this->assertDatabaseHas('central_invoices', ['id' => $invoice->id, 'status' => 'payment_submitted'], 'pgsql');
        $this->assertDatabaseHas('central_payments', ['invoice_id' => $invoice->id, 'tenant_id' => '001', 'status' => 'pending_review'], 'pgsql');
    }

    public function test_a_tenant_cannot_submit_a_payment_for_another_tenants_invoice(): void
    {
        CentralPlan::query()->updateOrCreate(['code' => 'starter'], ['name' => 'Starter', 'price_monthly' => 500000]);
        $this->provisionTenant();

        $otherTenant = Tenant::create(['id' => '777', 'name' => 'Other Co', 'plan' => 'starter']);
        $foreignInvoice = CentralInvoice::query()->create([
            'tenant_id' => $otherTenant->getKey(),
            'plan_code' => 'starter',
            'billing_period_start' => now()->startOfMonth(),
            'billing_period_end' => now()->endOfMonth(),
            'status' => 'issued',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => now()->addDays(14),
            'issued_at' => now(),
        ]);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post("/billing/invoices/{$foreignInvoice->id}/payments", ['amount' => 500000])
            ->assertForbidden();

        $this->dropTenantDatabaseIfExists('777');
        Tenant::query()->whereKey('777')->delete();
    }
}
