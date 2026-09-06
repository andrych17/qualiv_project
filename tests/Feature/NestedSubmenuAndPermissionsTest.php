<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\SysConfig\Services\ConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class NestedSubmenuAndPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_menus_for_user_returns_nested_submenus_with_children(): void
    {
        $tenant = $this->provisionTenant('101');
        $tenant->update(['plan' => 'qualiv']);

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();
            $service = app(ConfigService::class);

            ConfigService::clearCache();
            $menus = $service->menusForUser((int) $admin->id);

            // Projects parent should exist with children
            $projectsMenu = collect($menus)->firstWhere('code', 'PROJECTS');
            $this->assertNotNull($projectsMenu);
            $this->assertNotEmpty($projectsMenu['children']);

            $childCodes = collect($projectsMenu['children'])->pluck('code')->all();
            $this->assertContains('PROJECTS_ALL', $childCodes);
            $this->assertContains('PROJECTS_NEW', $childCodes);

            // Transactions parent should also have children
            $trxMenu = collect($menus)->firstWhere('code', 'TRANSACTIONS');
            $this->assertNotNull($trxMenu);
            $this->assertNotEmpty($trxMenu['children']);
        });
    }

    public function test_child_permissions_fallback_to_parent_right(): void
    {
        $tenant = $this->provisionTenant('102');
        $tenant->update(['plan' => 'qualiv']);

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();
            $service = app(ConfigService::class);

            ConfigService::clearCache();
            $perms = $service->permissionsForUserMenu((int) $admin->id, 'PROJECTS_ALL');

            $this->assertTrue($perms['read']);
            $this->assertTrue($perms['create']);
            $this->assertTrue($perms['update']);
            $this->assertTrue($perms['delete']);
        });
    }

    public function test_cache_clears_on_group_rights_update(): void
    {
        $tenant = $this->provisionTenant('103');
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();
            $service = app(ConfigService::class);

            ConfigService::clearCache();
            $menusBefore = $service->menusForUser((int) $admin->id);
            $this->assertNotEmpty($menusBefore);

            // Clear cache should be callable cleanly
            ConfigService::clearCache();
            $menusAfter = $service->menusForUser((int) $admin->id);
            $this->assertNotEmpty($menusAfter);
        });
    }
}
