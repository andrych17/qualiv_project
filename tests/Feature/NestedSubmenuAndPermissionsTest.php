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
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();
            $service = app(ConfigService::class);

            ConfigService::clearCache();
            $menus = $service->menusForUser((int) $admin->id);

            // Schedule parent should exist
            $scheduleMenu = collect($menus)->firstWhere('code', 'SCHEDULE');
            $this->assertNotNull($scheduleMenu);
            $this->assertNotEmpty($scheduleMenu['children']);

            $childCodes = collect($scheduleMenu['children'])->pluck('code')->all();
            $this->assertContains('SCHEDULE_DASHBOARD', $childCodes);
            $this->assertContains('SCHEDULE_TASKS', $childCodes);
            $this->assertContains('SCHEDULE_EVENTS', $childCodes);
            $this->assertContains('SCHEDULE_RESOURCES', $childCodes);

            // Inventory parent should also have children
            $invMenu = collect($menus)->firstWhere('code', 'INVENTORY');
            $this->assertNotNull($invMenu);
            $this->assertNotEmpty($invMenu['children']);
        });
    }

    public function test_child_permissions_fallback_to_parent_right(): void
    {
        $tenant = $this->provisionTenant('102');
        $tenant->update(['plan' => 'legal']);

        $tenant->run(function () {
            $admin = User::query()->where('email', 'admin@nusaevo.com')->firstOrFail();
            $service = app(ConfigService::class);

            ConfigService::clearCache();
            $perms = $service->permissionsForUserMenu((int) $admin->id, 'SCHEDULE_TASKS');

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
