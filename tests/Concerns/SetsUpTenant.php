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

    protected function tearDownSetsUpTenant(): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

        DB::purge('tenant');
        DB::disconnect('tenant');
    }

    protected function provisionTenant(string $tenantId = '001', string $email = 'admin@nusaevo.com', string $password = 'password'): Tenant
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

        DB::purge('tenant');
        DB::disconnect('tenant');

        $this->dropTenantDatabaseIfExists($tenantId);

        DB::table('tenant_user_lookups')->where('tenant_id', $tenantId)->delete();
        DB::table('domains')->where('tenant_id', $tenantId)->delete();
        if (DB::getSchemaBuilder()->hasTable('central_invoice_lines')) {
            $invoiceIds = DB::table('central_invoices')->where('tenant_id', $tenantId)->pluck('id');
            DB::table('central_invoice_lines')->whereIn('central_invoice_id', $invoiceIds)->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('central_invoices')) {
            DB::table('central_invoices')->where('tenant_id', $tenantId)->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('central_tenant_addons')) {
            DB::table('central_tenant_addons')->where('tenant_id', $tenantId)->delete();
        }
        if (DB::getSchemaBuilder()->hasTable('central_audit_logs')) {
            DB::table('central_audit_logs')->where('entity_id', $tenantId)->delete();
        }
        DB::table('tenants')->where('id', $tenantId)->delete();

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
                (new SysConfigSeeder)->run();
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

        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

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
        $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$dbName}' AND pid <> pg_backend_pid()");
        $pdo->exec('DROP DATABASE IF EXISTS "'.$dbName.'" WITH (FORCE)');
        $pdo = null;
    }
}
