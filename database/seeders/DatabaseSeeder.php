<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->find('001') ?? Tenant::create(['id' => '001']);

        $tenant->run(function () {
            User::query()->updateOrCreate(
                ['email' => 'admin@nusaevo.com'],
                [
                    'name' => 'Admin User',
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );

            $this->call(SysConfigSeeder::class);
            $this->call(InventorySeeder::class);
        });

        TenantUserLookup::query()->updateOrCreate(
            ['email' => 'admin@nusaevo.com'],
            ['tenant_id' => '001'],
        );
    }
}
