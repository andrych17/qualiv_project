<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralAuditLog;
use App\Modules\Central\Models\CentralDunningPolicy;
use App\Modules\Central\Models\CentralInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ApplyDunningCutoffCommandTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUpFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanUpFixtures();

        parent::tearDown();
    }

    private function cleanUpFixtures(): void
    {
        // SetsUpTenant disables transactional rollback — clean up explicitly so this test is
        // safe to re-run and doesn't leak state into tests that run later in the same suite.
        foreach (['804', '805'] as $id) {
            $this->dropTenantDatabaseIfExists($id);
            Tenant::query()->whereKey($id)->delete();
        }

        // central_audit_logs has no FK to tenants (entity_id is a free-form string), so it
        // doesn't cascade-delete with the tenant row above — purge explicitly, otherwise the
        // exact-count assertions below would accumulate across repeated runs.
        CentralAuditLog::query()->whereIn('entity_id', ['804', '805'])->delete();
    }

    public function test_it_flips_a_past_cutoff_tenant_to_read_only_and_never_reflips(): void
    {
        $tenant = Tenant::create(['id' => '804', 'name' => 'Cutoff Co', 'plan' => 'starter']);

        CentralDunningPolicy::query()->updateOrCreate(
            ['scope_type' => 'platform_default', 'scope_id' => null],
            ['reminder_offsets_days' => [-7, -3, -1, 3, 7], 'cutoff_days_after_due' => 14],
        );

        // due_date + 14 days cutoff is already 6 days in the past.
        $invoice = CentralInvoice::query()->create([
            'tenant_id' => '804',
            'plan_code' => 'starter',
            'billing_period_start' => now()->subMonth()->startOfMonth(),
            'billing_period_end' => now()->subMonth()->endOfMonth(),
            'status' => 'issued',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => today()->subDays(20),
            'issued_at' => now()->subMonth(),
        ]);

        Artisan::call('central:apply-dunning-cutoff');

        $this->assertSame('read_only', $tenant->refresh()->access_status);
        $this->assertDatabaseHas('central_invoices', ['id' => $invoice->id, 'status' => 'overdue']);
        $this->assertDatabaseHas('central_audit_logs', [
            'action' => 'access_status_changed',
            'entity_type' => 'tenant',
            'entity_id' => '804',
        ]);
        $this->assertSame(1, DB::table('central_audit_logs')
            ->where('entity_id', '804')->where('action', 'access_status_changed')->count());

        // Re-running the same day must not re-flip (and must not re-log) an already read_only tenant.
        Artisan::call('central:apply-dunning-cutoff');

        $this->assertSame('read_only', $tenant->refresh()->access_status);
        $this->assertSame(1, DB::table('central_audit_logs')
            ->where('entity_id', '804')->where('action', 'access_status_changed')->count());
    }

    public function test_it_skips_a_tenant_whose_invoice_was_paid_after_the_sweep_started(): void
    {
        $tenant = Tenant::create(['id' => '804', 'name' => 'Cutoff Co', 'plan' => 'starter']);

        CentralDunningPolicy::query()->updateOrCreate(
            ['scope_type' => 'platform_default', 'scope_id' => null],
            ['reminder_offsets_days' => [-7, -3, -1, 3, 7], 'cutoff_days_after_due' => 14],
        );

        // Past cutoff — but the invoice is already paid, so the sweep must not flip the tenant.
        CentralInvoice::query()->create([
            'tenant_id' => '804',
            'plan_code' => 'starter',
            'billing_period_start' => now()->subMonth()->startOfMonth(),
            'billing_period_end' => now()->subMonth()->endOfMonth(),
            'status' => 'paid',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => today()->subDays(20),
            'issued_at' => now()->subMonth(),
        ]);

        Artisan::call('central:apply-dunning-cutoff');

        $this->assertSame('active', $tenant->refresh()->access_status);
        $this->assertDatabaseMissing('central_audit_logs', [
            'action' => 'access_status_changed',
            'entity_id' => '804',
        ]);
    }

    public function test_it_does_not_cut_off_a_tenant_still_inside_the_window(): void
    {
        $tenant = Tenant::create(['id' => '805', 'name' => 'Fresh Invoice Co', 'plan' => 'starter']);

        CentralDunningPolicy::query()->updateOrCreate(
            ['scope_type' => 'platform_default', 'scope_id' => null],
            ['reminder_offsets_days' => [-7, -3, -1, 3, 7], 'cutoff_days_after_due' => 14],
        );

        CentralInvoice::query()->create([
            'tenant_id' => '805',
            'plan_code' => 'starter',
            'billing_period_start' => now()->startOfMonth(),
            'billing_period_end' => now()->endOfMonth(),
            'status' => 'issued',
            'amount_total' => 500000,
            'currency' => 'IDR',
            'due_date' => today()->addDays(5),
            'issued_at' => now(),
        ]);

        Artisan::call('central:apply-dunning-cutoff');

        $this->assertSame('active', $tenant->refresh()->access_status);
    }
}
