<?php

namespace Tests\Unit\Central;

use App\Modules\Central\Support\ModuleCatalog;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Boots the app container (needed for the Config facade) but touches no DB/HTTP —
 * ModuleCatalog::codes() is pure config-flattening logic.
 */
class ModuleCatalogTest extends TestCase
{
    public function test_flattens_and_dedupes_module_codes_across_plans(): void
    {
        Config::set('tenant_modules.plans', [
            'starter' => ['INVENTORY', 'DESIGN_SYSTEM'],
            'legal' => ['INVENTORY', 'LEGAL', 'CRM'],
        ]);

        $this->assertSame(
            ['CRM', 'DESIGN_SYSTEM', 'INVENTORY', 'LEGAL'],
            ModuleCatalog::codes(),
        );
    }

    public function test_empty_config_returns_empty_list(): void
    {
        Config::set('tenant_modules.plans', []);

        $this->assertSame([], ModuleCatalog::codes());
    }

    public function test_result_is_sorted(): void
    {
        Config::set('tenant_modules.plans', [
            'full' => ['PAYROLL', 'ACCOUNTING', 'HCM'],
        ]);

        $this->assertSame(['ACCOUNTING', 'HCM', 'PAYROLL'], ModuleCatalog::codes());
    }
}
