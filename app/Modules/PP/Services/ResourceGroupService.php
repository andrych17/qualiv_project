<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Models\ResourceGroupMember;
use Illuminate\Support\Facades\DB;

/** PP_SPECS.md §3E — resource group header + members CRUD, same header/lines sync pattern as BomService. */
class ResourceGroupService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): ResourceGroup
    {
        return DB::transaction(function () use ($data) {
            $group = ResourceGroup::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $this->syncMembers($group, $data['members'] ?? []);

            return $group->load('members');
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ResourceGroup $group, array $data): ResourceGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $group->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $group->is_active,
            ]);

            $this->syncMembers($group, $data['members'] ?? []);

            return $group->refresh()->load('members');
        });
    }

    public function delete(ResourceGroup $group): void
    {
        $group->delete();
    }

    /** @param  list<array<string, mixed>>  $members */
    private function syncMembers(ResourceGroup $group, array $members): void
    {
        $group->members()->delete();

        foreach ($members as $member) {
            if (empty($member['resource_type']) || empty($member['resource_ref_id'])) {
                continue;
            }

            ResourceGroupMember::query()->create([
                'resource_group_id' => $group->id,
                'resource_type' => $member['resource_type'],
                'resource_ref_id' => $member['resource_ref_id'],
            ]);
        }
    }
}
