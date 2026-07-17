<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ponytail: two tenants with distinct flavor seed (config/inventory/legal/custom fields)
        $tenants = [
            ['id' => '001', 'name' => 'Demo Firm A', 'plan' => 'legal'],
            ['id' => '002', 'name' => 'Demo Firm B', 'plan' => 'legal'],
        ];

        $users = [
            ['email' => 'admin@nusaevo.com', 'name' => 'Admin User'],
            ['email' => 'staff@nusaevo.com', 'name' => 'Staff User'],
            ['email' => 'viewer@nusaevo.com', 'name' => 'Viewer User'],
        ];

        foreach ($tenants as $t) {
            $tenant = Tenant::query()->find($t['id']);

            if (! $tenant) {
                // migrate:fresh drops central rows but leaves tenant_* DBs — clear orphans first
                $this->dropOrphanTenantDatabase($t['id']);
                $tenant = Tenant::create([
                    'id' => $t['id'],
                    'name' => $t['name'],
                    'plan' => $t['plan'],
                ]);
            } else {
                $tenant->update([
                    'name' => $t['name'],
                    'plan' => $t['plan'],
                ]);
            }

            $tenant->run(function () use ($users, $t) {
                foreach ($users as $u) {
                    // Slightly different display names per firm so Users page differs too
                    $name = $t['id'] === '002'
                        ? str_replace('User', 'User (B)', $u['name'])
                        : $u['name'];

                    User::query()->updateOrCreate(
                        ['email' => $u['email']],
                        [
                            'name' => $name,
                            'password' => 'password',
                            'email_verified_at' => now(),
                        ],
                    );
                }

                config(['demo.tenant' => $t]);
                $this->call(SysConfigSeeder::class);
                $this->call(TenantFlavorSeeder::class);
            });

            foreach ($users as $u) {
                if ($u['email'] === 'viewer@nusaevo.com' && $t['id'] !== '001') {
                    continue;
                }
                TenantUserLookup::query()->updateOrCreate(
                    ['email' => $u['email'], 'tenant_id' => $t['id']],
                    [],
                );
            }
        }
    }

    private function dropOrphanTenantDatabase(string $tenantId): void
    {
        $dbName = 'tenant_'.$tenantId;
        $pdo = DB::connection('pgsql')->getPdo();
        $pdo->exec(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.$pdo->quote($dbName).' AND pid <> pg_backend_pid()'
        );
        $pdo->exec('DROP DATABASE IF EXISTS "'.$dbName.'"');
    }
}
