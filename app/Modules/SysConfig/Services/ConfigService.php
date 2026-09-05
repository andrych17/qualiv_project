<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\ConfigConst;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use App\Modules\SysConfig\Models\TenantModule;
use App\Services\TenantFeatureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ConfigService
{
    public function __construct(
        protected ConfigAuditLogger $audit,
    ) {}

    /** @var array<string, array> */
    protected static array $memoMenus = [];

    /** @var array<string, array{create: bool, read: bool, update: bool, delete: bool}> */
    protected static array $memoPerms = [];

    public static function clearCache(): void
    {
        self::$memoMenus = [];
        self::$memoPerms = [];

        try {
            if (request()->hasSession()) {
                foreach (array_keys(request()->session()->all()) as $k) {
                    if (str_starts_with((string) $k, 'sysconfig_')) {
                        request()->session()->forget($k);
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore if session not available in CLI
        }
    }

    /**
     * Active menus the user may see (has at least R in any group), nested with children if present.
     *
     * @return list<array{code: string, label: string, href: string, icon: string|null, seq: int, header: string|null, children: list<array{code: string, label: string, href: string, icon: string|null, seq: int}>}>
     */
    public function menusForUser(int $userId, string $appCode = 'NUSAEVO'): array
    {
        $tenantKey = tenancy()->initialized ? (string) tenant()->getTenantKey() : 'central';
        $memoKey = "{$tenantKey}_{$userId}_{$appCode}";

        if (isset(self::$memoMenus[$memoKey])) {
            return self::$memoMenus[$memoKey];
        }

        $groupIds = ConfigGroupUser::query()
            ->where('user_id', $userId)
            ->pluck('group_id');

        if ($groupIds->isEmpty()) {
            return self::$memoMenus[$memoKey] = [];
        }

        $directMenuIds = ConfigRight::query()
            ->whereIn('group_id', $groupIds)
            ->where('app_code', $appCode)
            ->where('trustee', 'like', '%R%')
            ->pluck('menu_id')
            ->all();

        $directMenuMap = array_flip($directMenuIds);

        // Fetch all active menus for this app once
        $allMenus = ConfigMenu::query()
            ->where('app_code', $appCode)
            ->where('status_code', 'A')
            ->orderBy('seq')
            ->get();

        $menusById = $allMenus->keyBy('id');

        // Resolve all ancestor parent IDs in memory without DB queries
        $allowedMenuIds = $directMenuMap;
        foreach ($directMenuIds as $mid) {
            $curr = $menusById->get($mid);
            while ($curr && $curr->parent_id) {
                $allowedMenuIds[$curr->parent_id] = true;
                $curr = $menusById->get($curr->parent_id);
            }
        }

        // Module enablement resolution in-memory
        $enabledModules = array_flip(array_map('strtoupper', app(TenantFeatureService::class)->enabledModules()));
        $inactiveModules = array_flip(
            TenantModule::query()
                ->where('is_active', false)
                ->pluck('module_code')
                ->map(fn ($c) => strtoupper((string) $c))
                ->all()
        );

        $visibleMenus = $allMenus->filter(function (ConfigMenu $m) use ($allowedMenuIds, $enabledModules, $inactiveModules) {
            if (! isset($allowedMenuIds[$m->id])) {
                return false;
            }
            if ($m->module_code !== null && $m->module_code !== '') {
                $code = strtoupper($m->module_code);
                if (! isset($enabledModules[$code]) || isset($inactiveModules[$code])) {
                    return false;
                }
            }

            return true;
        });

        $childrenByParent = $visibleMenus->whereNotNull('parent_id')->groupBy('parent_id');
        $parents = $visibleMenus->whereNull('parent_id');

        $result = $parents->map(function (ConfigMenu $parent) use ($childrenByParent, $directMenuMap) {
            $level2Items = ($childrenByParent->get($parent->id) ?? collect())
                ->filter(function (ConfigMenu $l2) use ($childrenByParent, $directMenuMap, $parent) {
                    if (isset($directMenuMap[$l2->id]) || isset($directMenuMap[$parent->id])) {
                        return true;
                    }
                    $l3Children = $childrenByParent->get($l2->id) ?? collect();

                    return $l3Children->contains(fn ($l3) => isset($directMenuMap[$l3->id]));
                })
                ->map(function (ConfigMenu $l2) use ($childrenByParent, $directMenuMap, $parent) {
                    $level3Items = ($childrenByParent->get($l2->id) ?? collect())
                        ->filter(fn (ConfigMenu $l3) => isset($directMenuMap[$l3->id]) || isset($directMenuMap[$l2->id]) || isset($directMenuMap[$parent->id]))
                        ->map(fn (ConfigMenu $l3) => [
                            'code' => $l3->code,
                            'label' => $l3->menu_caption,
                            'href' => $l3->menu_link ?: '#',
                            'icon' => $l3->icon,
                            'seq' => $l3->seq,
                        ])
                        ->values()
                        ->all();

                    return [
                        'code' => $l2->code,
                        'label' => $l2->menu_caption,
                        'href' => $l2->menu_link ?: '#',
                        'icon' => $l2->icon,
                        'seq' => $l2->seq,
                        'children' => $level3Items,
                    ];
                })
                ->values()
                ->all();

            return [
                'code' => $parent->code,
                'label' => $parent->menu_caption,
                'href' => $parent->menu_link ?: '#',
                'icon' => $parent->icon,
                'seq' => $parent->seq,
                'header' => $parent->menu_header,
                'children' => $level2Items,
            ];
        })->values()->all();

        return self::$memoMenus[$memoKey] = $result;
    }

    /** @return array{create: bool, read: bool, update: bool, delete: bool} */
    public function permissionsForUserMenu(int $userId, string $menuCode, string $appCode = 'NUSAEVO'): array
    {
        $tenantKey = tenancy()->initialized ? (string) tenant()->getTenantKey() : 'central';
        $memoKey = "{$tenantKey}_{$userId}_{$appCode}_{$menuCode}";

        if (isset(self::$memoPerms[$memoKey])) {
            return self::$memoPerms[$memoKey];
        }

        $groupIds = ConfigGroupUser::query()
            ->where('user_id', $userId)
            ->pluck('group_id');

        if ($groupIds->isEmpty()) {
            return self::$memoPerms[$memoKey] = ConfigRight::parseTrustee('');
        }

        $trustees = ConfigRight::query()
            ->whereIn('group_id', $groupIds)
            ->where('menu_code', $menuCode)
            ->where('app_code', $appCode)
            ->pluck('trustee');

        // Fallback to ancestors if child has no direct trustee (up to 3 levels)
        if ($trustees->isEmpty()) {
            $currMenu = ConfigMenu::query()->where('code', $menuCode)->where('app_code', $appCode)->first();
            while ($currMenu && $currMenu->parent_id && $trustees->isEmpty()) {
                $parent = ConfigMenu::query()->find($currMenu->parent_id);
                if ($parent) {
                    $trustees = ConfigRight::query()
                        ->whereIn('group_id', $groupIds)
                        ->where('menu_code', $parent->code)
                        ->where('app_code', $appCode)
                        ->pluck('trustee');
                    $currMenu = $parent;
                } else {
                    break;
                }
            }
        }

        $merged = '';
        foreach ($trustees as $t) {
            foreach (str_split((string) $t) as $ch) {
                if (! str_contains($merged, $ch)) {
                    $merged .= $ch;
                }
            }
        }

        $result = ConfigRight::parseTrustee($merged);

        return self::$memoPerms[$memoKey] = $result;
    }

    public function constsByGroup(string $constGroup): Collection
    {
        return ConfigConst::query()
            ->where('const_group', $constGroup)
            ->active()
            ->orderBy('seq')
            ->get();
    }

    public function constValue(string $constGroup, ?string $groupCode = null): ?ConfigConst
    {
        return ConfigConst::query()
            ->where('const_group', $constGroup)
            ->active()
            ->when($groupCode !== null, fn ($q) => $q->where('group_code', $groupCode))
            ->orderBy('seq')
            ->first();
    }

    /**
     * Two-tier resolution (SYSCONFIG_SPECS.md §3E):
     * 1. prefer appl_id match, else appl_id IS NULL
     * 2. inside that tier: user_id > group_id > neither
     * Winner is one whole row — never merged.
     */
    public function get(string $constGroup, string $groupCode, ?string $applId = null, ?int $groupId = null, ?int $userId = null): mixed
    {
        $key = $this->cacheKey($constGroup, $applId, $groupId, $userId, $groupCode);

        return Cache::remember($key, 3600, function () use ($constGroup, $groupCode, $applId, $groupId, $userId) {
            $row = $this->resolveRow($constGroup, $groupCode, $applId, $groupId, $userId);

            return $row ? $this->cast($row) : null;
        });
    }

    /** @return Collection<int, ConfigConst> */
    public function getGroup(string $constGroup, ?string $applId = null, ?int $groupId = null, ?int $userId = null): Collection
    {
        $codes = ConfigConst::query()
            ->where('const_group', $constGroup)
            ->active()
            ->orderBy('seq')
            ->pluck('group_code')
            ->unique()
            ->values();

        return $codes
            ->map(fn (string $code) => $this->resolveRow($constGroup, $code, $applId, $groupId, $userId))
            ->filter()
            ->sortBy(fn (ConfigConst $row) => $row->seq)
            ->values();
    }

    public function set(
        string $constGroup,
        string $groupCode,
        mixed $value,
        ?string $applId = null,
        ?int $groupId = null,
        ?int $userId = null,
        string $valueType = 'text',
    ): ConfigConst {
        $query = ConfigConst::query()
            ->where('const_group', $constGroup)
            ->where('group_code', $groupCode)
            ->where('appl_id', $applId)
            ->where('group_id', $groupId)
            ->where('user_id', $userId);

        /** @var ConfigConst|null $row */
        $row = $query->first();
        $before = $row?->only($this->auditFields());
        $stored = is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? null : (string) $value);

        if ($row) {
            $row->update([
                'value' => $stored,
                'value_type' => $valueType,
                'is_active' => true,
            ]);
            $row->refresh();
            $this->audit->log('config_consts', $row->id, 'updated', $before, $row->only($this->auditFields()));
        } else {
            $row = ConfigConst::query()->create([
                'appl_id' => $applId,
                'group_id' => $groupId,
                'user_id' => $userId,
                'const_group' => $constGroup,
                'group_code' => $groupCode,
                'value' => $stored,
                'value_type' => $valueType,
                'is_active' => true,
                'seq' => 0,
            ]);
            $this->audit->log('config_consts', $row->id, 'created', null, $row->only($this->auditFields()));
        }

        $this->bumpCache($constGroup);

        return $row;
    }

    public function resolveRow(string $constGroup, string $groupCode, ?string $applId, ?int $groupId, ?int $userId): ?ConfigConst
    {
        $candidates = ConfigConst::query()
            ->where('const_group', $constGroup)
            ->where('group_code', $groupCode)
            ->active()
            ->where(function ($q) {
                $q->whereNull('effective_date')->orWhereDate('effective_date', '<=', now()->toDateString());
            })
            ->where(function ($q) use ($applId) {
                $q->whereNull('appl_id');
                if ($applId !== null) {
                    $q->orWhere('appl_id', $applId);
                }
            })
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $row) {
            $score = $this->scopeScore($row, $applId, $groupId, $userId);
            if ($score === null) {
                continue;
            }
            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @return list<string> */
    private function auditFields(): array
    {
        return ['appl_id', 'group_id', 'user_id', 'const_group', 'group_code', 'value', 'value_type', 'str1', 'str2', 'num1', 'num2', 'note1', 'seq', 'effective_date', 'is_active'];
    }

    private function scopeScore(ConfigConst $row, ?string $applId, ?int $groupId, ?int $userId): ?int
    {
        if ($row->appl_id !== null && $row->appl_id !== $applId) {
            return null;
        }
        if ($row->user_id !== null && $row->user_id !== $userId) {
            return null;
        }
        if ($row->group_id !== null && $row->user_id === null && $row->group_id !== $groupId) {
            return null;
        }

        $score = 0;
        if ($row->appl_id !== null && $row->appl_id === $applId) {
            $score += 100;
        }
        if ($row->user_id !== null && $row->user_id === $userId) {
            $score += 10;
        } elseif ($row->group_id !== null && $row->group_id === $groupId) {
            $score += 5;
        }

        return $score;
    }

    private function cast(ConfigConst $row): mixed
    {
        if ($row->value !== null && $row->value !== '') {
            return match ($row->value_type) {
                'number' => is_numeric($row->value) ? $row->value + 0 : $row->value,
                'bool' => in_array(strtolower((string) $row->value), ['1', 'true', 'yes', 'y', 'on'], true),
                default => $row->value,
            };
        }

        if ($row->value_type === 'bool') {
            if ($row->num1 !== null) {
                return (float) $row->num1 > 0;
            }

            return in_array(strtolower((string) $row->str1), ['1', 'true', 'yes', 'y', 'on'], true);
        }

        if ($row->num1 !== null && ($row->str1 === null || $row->str1 === '')) {
            return (float) $row->num1;
        }

        return $row->str1;
    }

    private function cacheKey(string $constGroup, ?string $applId, ?int $groupId, ?int $userId, string $groupCode): string
    {
        $ver = (int) Cache::get($this->versionKey($constGroup), 1);
        $tenant = tenancy()->initialized ? (string) tenant()->getTenantKey() : 'central';

        $day = now()->toDateString();

        return "tenant:{$tenant}:config:{$constGroup}:{$applId}:{$groupId}:{$userId}:{$groupCode}:{$day}:{$ver}";
    }

    public function bumpCache(string $constGroup): void
    {
        $key = $this->versionKey($constGroup);
        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }
        Cache::increment($key);
    }

    private function versionKey(string $constGroup): string
    {
        $tenant = tenancy()->initialized ? (string) tenant()->getTenantKey() : 'central';

        return "tenant:{$tenant}:config:ver:{$constGroup}";
    }
}
