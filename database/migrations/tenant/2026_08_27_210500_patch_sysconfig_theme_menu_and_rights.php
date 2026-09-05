<?php

declare(strict_types=1);

use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure CONFIG_THEME menu exists in SYSCONFIG.config_menus
        $menu = ConfigMenu::query()->updateOrCreate(
            ['app_code' => 'NUSAEVO', 'code' => 'CONFIG_THEME'],
            [
                'menu_header' => 'System',
                'menu_caption' => 'Theme',
                'menu_link' => '/config/theme',
                'icon' => 'Palette',
                'seq' => 228,
                'status_code' => 'A',
                'module_code' => null,
            ],
        );

        // 2. Ensure ADMIN group has CRUD rights for CONFIG_THEME
        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();
        if ($adminGroup) {
            ConfigRight::query()->updateOrCreate(
                ['group_id' => $adminGroup->id, 'menu_id' => $menu->id],
                [
                    'group_code' => $adminGroup->code,
                    'menu_code' => $menu->code,
                    'menu_seq' => $menu->seq,
                    'trustee' => 'CRUD',
                    'app_code' => 'NUSAEVO',
                ],
            );
        }

        // 3. Remove CONFIG_THEME rights from STAFF and VIEWER if present
        $nonAdminGroups = ConfigGroup::query()->whereIn('code', ['STAFF', 'VIEWER'])->get();
        foreach ($nonAdminGroups as $group) {
            ConfigRight::query()
                ->where('group_id', $group->id)
                ->where('menu_id', $menu->id)
                ->delete();
        }

        // 4. Ensure default active_theme config_const exists
        ConfigConst::query()->firstOrCreate(
            ['const_group' => 'THEME', 'group_code' => 'active_theme'],
            [
                'value' => 'classic-navy',
                'str1' => 'classic-navy',
                'note1' => 'Tenant active UI theme token palette',
                'is_active' => true,
                'value_type' => 'text',
            ],
        );
    }

    public function down(): void
    {
        ConfigRight::query()->where('menu_code', 'CONFIG_THEME')->delete();
        ConfigMenu::query()->where('code', 'CONFIG_THEME')->delete();
    }
};
