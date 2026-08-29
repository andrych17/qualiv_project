<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Support\Facades\DB;

class ConfigMenuService
{
    private const APP = 'NUSAEVO';

    public function create(array $data): ConfigMenu
    {
        return DB::transaction(function () use ($data) {
            $menu = ConfigMenu::query()->create([
                'code' => $data['code'],
                'app_code' => self::APP,
                'menu_header' => $data['menu_header'] ?? 'Main',
                'menu_caption' => $data['menu_caption'],
                'menu_link' => $data['menu_link'] ?: '#',
                'icon' => $data['icon'] ?: null,
                'parent_id' => $data['parent_id'] ?: null,
                'seq' => (int) ($data['seq'] ?? 0),
                'status_code' => $data['status_code'] ?? 'A',
                'module_code' => ($data['module_code'] ?? null) ?: null,
            ]);

            // ponytail: new menus invisible until some group has R — auto-grant ADMIN CRUD
            $admin = ConfigGroup::query()
                ->where('app_code', self::APP)
                ->where('code', 'ADMIN')
                ->first();

            if ($admin) {
                ConfigRight::query()->create([
                    'group_id' => $admin->id,
                    'menu_id' => $menu->id,
                    'group_code' => $admin->code,
                    'menu_code' => $menu->code,
                    'menu_seq' => $menu->seq,
                    'trustee' => 'CRUD',
                    'app_code' => self::APP,
                ]);
            }

            ConfigService::clearCache();

            return $menu;
        });
    }

    public function update(ConfigMenu $menu, array $data): ConfigMenu
    {
        $menu->update([
            'code' => $data['code'],
            'menu_header' => $data['menu_header'] ?? $menu->menu_header,
            'menu_caption' => $data['menu_caption'],
            'menu_link' => $data['menu_link'] ?: '#',
            'icon' => $data['icon'] ?: null,
            'parent_id' => $data['parent_id'] ?: null,
            'seq' => (int) ($data['seq'] ?? $menu->seq),
            'status_code' => $data['status_code'] ?? $menu->status_code,
            'module_code' => array_key_exists('module_code', $data) ? ($data['module_code'] ?: null) : $menu->module_code,
        ]);

        // Keep denormalized rights in sync when code/seq change
        ConfigRight::query()
            ->where('menu_id', $menu->id)
            ->update([
                'menu_code' => $menu->code,
                'menu_seq' => $menu->seq,
            ]);

        ConfigService::clearCache();

        return $menu->refresh();
    }

    public function delete(ConfigMenu $menu): void
    {
        DB::transaction(function () use ($menu) {
            ConfigRight::query()->where('menu_id', $menu->id)->delete();
            ConfigMenu::query()->where('parent_id', $menu->id)->update(['parent_id' => null]);
            $menu->delete();
            ConfigService::clearCache();
        });
    }
}
