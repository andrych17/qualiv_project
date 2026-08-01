<?php

namespace App\Modules\Config\Services;

use App\Modules\Config\Models\ConfigConst;
use App\Modules\Config\Models\ConfigGroupUser;
use App\Modules\Config\Models\ConfigMenu;
use App\Modules\Config\Models\ConfigRight;
use App\Services\TenantFeatureService;
use Illuminate\Support\Collection;

class ConfigService
{
    /**
     * Active menus the user may see (has at least R in any group).
     *
     * @return list<array{code: string, label: string, href: string, icon: string|null, seq: int, header: string|null}>
     */
    public function menusForUser(int $userId, string $appCode = 'NUSAEVO'): array
    {
        $groupIds = ConfigGroupUser::query()
            ->where('user_id', $userId)
            ->pluck('group_id');

        if ($groupIds->isEmpty()) {
            return [];
        }

        $menuIds = ConfigRight::query()
            ->whereIn('group_id', $groupIds)
            ->where('app_code', $appCode)
            ->where('trustee', 'like', '%R%')
            ->pluck('menu_id')
            ->unique();

        return ConfigMenu::query()
            ->whereIn('id', $menuIds)
            ->where('app_code', $appCode)
            ->where('status_code', 'A')
            ->orderBy('seq')
            ->get()
            ->filter(function (ConfigMenu $m) {
                // System CONFIG_*/Dashboard/Design System always allowed if trustee ok
                // (see config/tenant_modules.php); domain menus need the plan's module flag.
                if (str_starts_with($m->code, 'CONFIG_') || $m->code === 'DASHBOARD' || $m->code === 'DESIGN_SYSTEM') {
                    return true;
                }

                return app(TenantFeatureService::class)->enabled($m->code);
            })
            ->map(fn (ConfigMenu $m) => [
                'code' => $m->code,
                'label' => $m->menu_caption,
                'href' => $m->menu_link ?: '#',
                'icon' => $m->icon,
                'seq' => $m->seq,
                'header' => $m->menu_header,
            ])
            ->values()
            ->all();
    }

    /** @return array{create: bool, read: bool, update: bool, delete: bool} */
    public function permissionsForUserMenu(int $userId, string $menuCode, string $appCode = 'NUSAEVO'): array
    {
        $groupIds = ConfigGroupUser::query()
            ->where('user_id', $userId)
            ->pluck('group_id');

        if ($groupIds->isEmpty()) {
            return ConfigRight::parseTrustee('');
        }

        // Union trustees across groups (any C/R/U/D wins).
        $trustees = ConfigRight::query()
            ->whereIn('group_id', $groupIds)
            ->where('menu_code', $menuCode)
            ->where('app_code', $appCode)
            ->pluck('trustee');

        $merged = '';
        foreach ($trustees as $t) {
            foreach (str_split((string) $t) as $ch) {
                if (! str_contains($merged, $ch)) {
                    $merged .= $ch;
                }
            }
        }

        return ConfigRight::parseTrustee($merged);
    }

    public function constsByGroup(string $constGroup): Collection
    {
        return ConfigConst::query()
            ->where('const_group', $constGroup)
            ->orderBy('seq')
            ->get();
    }

    public function constValue(string $constGroup, ?string $groupCode = null): ?ConfigConst
    {
        return ConfigConst::query()
            ->where('const_group', $constGroup)
            ->when($groupCode !== null, fn ($q) => $q->where('group_code', $groupCode))
            ->orderBy('seq')
            ->first();
    }
}
