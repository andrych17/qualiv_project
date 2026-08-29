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

        DB::purge('tenant');
        DB::disconnect('tenant');

        $host = config('database.connections.pgsql.host', 'postgres');
        $port = config('database.connections.pgsql.port', 5432);
        $user = config('database.connections.pgsql.username', 'nusa_test');
        $pass = config('database.connections.pgsql.password', 'secret');
        $centralDb = config('database.connections.pgsql.database', 'nusaevo_testing');

        $pdo = new \PDO("pgsql:host={$host};port={$port};dbname={$centralDb}", $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('DROP DATABASE IF EXISTS "'.$dbName.'" WITH (FORCE)');
    }
}
