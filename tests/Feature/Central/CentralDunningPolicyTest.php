<?php

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralDunningPolicy;
use App\Modules\Central\Services\CentralDunningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class CentralDunningPolicyTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanUpTenants();
        // SetsUpTenant disables transactional rollback, so nothing here is isolated between
        // test methods (or other test classes) automatically — this test specifically needs
        // a genuinely empty table to assert the "nothing resolves" throw path.
        CentralDunningPolicy::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTenants();
        CentralDunningPolicy::query()->delete();

        parent::tearDown();
    }

    private function cleanUpTenants(): void
    {
        foreach (['801', '802'] as $id) {
            $this->dropTenantDatabaseIfExists($id);
            Tenant::query()->whereKey($id)->delete();
        }
    }

    public function test_resolution_prefers_tenant_over_plan_over_platform_default(): void
    {
        // A plan code no other test in this suite uses — SetsUpTenant-based tests commit
        // rows without rollback (see CentralPlanCrudTest's own note), so a shared code like
        // 'starter' here would leak a plan-scoped policy into every other dunning test that
        // also happens to use the 'starter' plan.
        $tenant = Tenant::create(['id' => '801', 'name' => 'Dunning Co', 'plan' => 'dunning_policy_test_plan']);

        CentralDunningPolicy::query()->create([
            'scope_type' => 'platform_default',
            'reminder_offsets_days' => [-7, -3, -1, 3, 7],
            'cutoff_days_after_due' => 14,
        ]);

        $resolved = app(CentralDunningService::class)->resolvePolicyFor($tenant);
        $this->assertSame('platform_default', $resolved->scope_type);

        CentralDunningPolicy::query()->create([
            'scope_type' => 'plan',
            'scope_id' => 'dunning_policy_test_plan',
            'reminder_offsets_days' => [-5, 5],
            'cutoff_days_after_due' => 10,
        ]);

        $resolved = app(CentralDunningService::class)->resolvePolicyFor($tenant);
        $this->assertSame('plan', $resolved->scope_type);
        $this->assertSame(10, $resolved->cutoff_days_after_due);

        CentralDunningPolicy::query()->create([
            'scope_type' => 'tenant',
            'scope_id' => '801',
            'reminder_offsets_days' => [-1],
            'cutoff_days_after_due' => 30,
        ]);

        $resolved = app(CentralDunningService::class)->resolvePolicyFor($tenant);
        $this->assertSame('tenant', $resolved->scope_type);
        $this->assertSame(30, $resolved->cutoff_days_after_due);
    }

    public function test_resolution_throws_when_nothing_resolves(): void
    {
        $tenant = Tenant::create(['id' => '802', 'name' => 'No Policy Co', 'plan' => 'starter']);

        $this->expectException(RuntimeException::class);

        app(CentralDunningService::class)->resolvePolicyFor($tenant);
    }
}
