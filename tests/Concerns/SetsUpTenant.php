<?php

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Database\Seeders\SysConfigSeeder;
use Illuminate\Support\Facades\DB;

trait SetsUpTenant
{
    /**
     * DROP DATABASE cannot run inside RefreshDatabase's transaction.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [];

    protected function provisionTenant(string $tenantId = '001', string $email = 'admin@nusaevo.com', string $password = 'password'): Tenant
    {
        $this->dropTenantDatabaseIfExists($tenantId);

        Tenant::query()->whereKey($tenantId)->delete();
        TenantUserLookup::query()->where('tenant_id', $tenantId)->delete();

        $tenant = Tenant::create(['id' => $tenantId]);

        $tenant->run(function () use ($email, $password) {
            User::factory()->create([
                'name' => 'Admin User',
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);

            // SysConfigSeeder keys off admin@nusaevo.com — keep email aligned for tests.
            if ($email === 'admin@nusaevo.com') {
                $this->seed(SysConfigSeeder::class);
            }
        });

        TenantUserLookup::query()->updateOrCreate(
            ['email' => $email, 'tenant_id' => $tenantId],
            [],
        );

        return $tenant;
    }

    protected function dropTenantDatabaseIfExists(string $tenantId): void
    {
        $dbName = 'tenant_'.$tenantId;

        // Use a dedicated connection so we are not inside a test transaction.
        DB::purge('pgsql');
        $pdo = DB::connection('pgsql')->getPdo();
        // ponytail: WITH (FORCE) (PG13+) terminates stray connections server-side.
        // The shared dev instance has GUI/backup clients that auto-connect to every
        // new database (including tenant_* mid-test); tests therefore run as the
        // dedicated nusa_test SUPERUSER role so teardown can always drop cleanly.
        $pdo->exec('DROP DATABASE IF EXISTS "'.$dbName.'" WITH (FORCE)');
    }
}
