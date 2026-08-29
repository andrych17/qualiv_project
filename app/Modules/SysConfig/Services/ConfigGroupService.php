<?php

namespace App\Modules\SysConfig\Services;

use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Modules\SysConfig\Models\ConfigMenu;
use App\Modules\SysConfig\Models\ConfigRight;
use Illuminate\Support\Facades\DB;

class ConfigGroupService
{
    private const APP = 'NUSAEVO';

    public function create(array $data): ConfigGroup
    {
        return ConfigGroup::query()->create([
            'code' => $data['code'],
            'app_code' => self::APP,
            'descr' => $data['descr'] ?? null,
            'status_code' => $data['status_code'] ?? 'A',
        ]);
    }

    public function update(ConfigGroup $group, array $data): ConfigGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $group->update([
                'code' => $data['code'],
                'descr' => $data['descr'] ?? null,
                'status_code' => $data['status_code'] ?? $group->status_code,
            ]);

            ConfigRight::query()
                ->where('group_id', $group->id)
                ->update(['group_code' => $group->code]);

            ConfigGroupUser::query()
                ->where('group_id', $group->id)
                ->update(['group_code' => $group->code]);

            if (array_key_exists('rights', $data)) {
                $this->syncRights($group, $data['rights'] ?? []);
            }

            if (array_key_exists('user_ids', $data)) {
                $this->syncUsers($group, $data['user_ids'] ?? []);
            }

            ConfigService::clearCache();

            return $group->refresh();
        });
    }

    public function delete(ConfigGroup $group): void
    {
        DB::transaction(function () use ($group) {
            ConfigRight::query()->where('group_id', $group->id)->delete();
            ConfigGroupUser::query()->where('group_id', $group->id)->delete();
            $group->delete();
            ConfigService::clearCache();
        });
    }

    /**
     * @param  list<array{menu_id: int, seq?: int|string|null, create?: bool, read?: bool, update?: bool, delete?: bool}>  $rights
     */
    private function syncRights(ConfigGroup $group, array $rights): void
    {
        $keepMenuIds = [];

        foreach ($rights as $row) {
            $menuId = (int) ($row['menu_id'] ?? 0);
            if ($menuId < 1) {
                continue;
            }

            $menu = ConfigMenu::query()->find($menuId);
            if (! $menu) {
                continue;
            }

            if (array_key_exists('seq', $row) && $row['seq'] !== null && $row['seq'] !== '') {
                $seqVal = (int) $row['seq'];
                if ($menu->seq !== $seqVal) {
                    $menu->update(['seq' => $seqVal]);
                    ConfigRight::query()
                        ->where('menu_id', $menu->id)
                        ->update(['menu_seq' => $seqVal]);
                }
            }

            $trustee = ConfigRight::prepareTrusteeString([
                'create' => (bool) ($row['create'] ?? false),
                'read' => (bool) ($row['read'] ?? false),
                'update' => (bool) ($row['update'] ?? false),
                'delete' => (bool) ($row['delete'] ?? false),
            ]);

            if ($trustee === '') {
                ConfigRight::query()
                    ->where('group_id', $group->id)
                    ->where('menu_id', $menuId)
                    ->delete();

                continue;
            }

            ConfigRight::query()->updateOrCreate(
                ['group_id' => $group->id, 'menu_id' => $menuId],
                [
                    'group_code' => $group->code,
                    'menu_code' => $menu->code,
                    'menu_seq' => $menu->seq,
                    'trustee' => $trustee,
                    'app_code' => self::APP,
                ],
            );

            $keepMenuIds[] = $menuId;
        }

        $query = ConfigRight::query()->where('group_id', $group->id);
        if ($keepMenuIds !== []) {
            $query->whereNotIn('menu_id', $keepMenuIds);
        }
        $query->delete();
    }

    /** @param  list<int|string>  $userIds */
    private function syncUsers(ConfigGroup $group, array $userIds): void
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        ConfigGroupUser::query()
            ->where('group_id', $group->id)
            ->when($ids->isNotEmpty(), fn ($q) => $q->whereNotIn('user_id', $ids))
            ->delete();

        foreach ($ids as $userId) {
            ConfigGroupUser::query()->updateOrCreate(
                ['group_id' => $group->id, 'user_id' => $userId],
                ['group_code' => $group->code],
            );
        }
    }
}
