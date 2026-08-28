<?php

namespace App\Jobs;

use Database\Seeders\SysConfigSeeder;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Seeds base SysConfig (groups, menus, rights, constants) for a newly provisioned tenant DB.
 * Runs synchronously during TenantCreated so every tenant has full menus & groups out of the box.
 */
class SeedTenantSysConfig
{
    public function __construct(
        protected TenantWithDatabase $tenant,
    ) {}

    public function handle(): void
    {
        $this->tenant->run(function () {
            (new SysConfigSeeder)->run();
        });
    }
}
