<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralInvoice;
use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Services\CentralAccessStatusCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class EnsureTenantStandingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function tearDown(): void
    {
        // SetsUpTenant disables transactional rollback — without this, tenant 001 (plus its
        // cascade-deleted central_invoices/central_payments rows) would leak into whatever
        // test runs next in the suite (e.g. GenerateCentralInvoicesCommandTest's exact-count
        // assertion on central_invoices).
        $this->dropTenantDatabaseIfExists('001');
        Tenant::query()->whereKey('001')->delete();

        parent::tearDown();
    }

    public function test_a_state_changing_request_is_blocked_once_read_only_and_allowed_while_active(): void
    {
        $this->provisionTenant();
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        // Active: a normal write goes through.
        $this->patch('/profile', ['name' => 'Admin User', 'email' => 'admin@nusaevo.com'])
            ->assertSessionHasNoErrors();

        Tenant::query()->whereKey('001')->update(['access_status' => 'read_only']);
        app(CentralAccessStatusCache::class)->invalidate('001');

        // Read-only: the same write is now blocked and routed to the Billing screen (§5's
        // direct link back to active), with the calm message in the session flash.
        $this->patch('/profile', ['name' => 'Admin User Changed', 'email' => 'admin@nusaevo.com'])
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('error');

        // GET requests are never blocked (read access stays available while read_only).
        $this->get('/profile')->assertOk();
    }

    public function test_billing_payment_submission_stays_allowed_even_while_read_only(): void
    {
        Storage::fake('s3');

        CentralPlan::query()->updateOrCreate(['code' => 'starter'], ['name' => 'Starter', 'price_monthly' => 500000]);
        $this->provisionTenant();
        Tenant::query()->whereKey('001')->update(['plan' => 'starter', 'access_status' => 'read_only']);
        app(CentralAccessStatusCache::class)->invalidate('001');

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

        $this->post("/billing/invoices/{$invoice->id}/payments", [
            'amount' => 500000,
            'paid_at' => now()->toDateString(),
        ])->assertRedirect(route('billing.index'));
    }
}
