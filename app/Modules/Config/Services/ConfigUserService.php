<?php

namespace App\Modules\Config\Services;

use App\Models\User;
use App\Modules\Config\Models\ConfigGroup;
use App\Modules\Config\Models\ConfigGroupUser;
use App\Services\TenantMembershipService;
use Illuminate\Support\Facades\DB;

class ConfigUserService
{
    public function __construct(
        protected TenantMembershipService $membership,
    ) {}

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
                'email_verified_at' => now(),
            ]);

            $tenantId = (string) tenant('id');
            $this->membership->ensureLookup($user->email, $tenantId);

            if (! empty($data['group_ids'])) {
                $this->syncGroups($user, $data['group_ids']);
            }

            return $user;
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $oldEmail = $user->email;
            $payload = [
                'name' => $data['name'],
                'email' => strtolower($data['email']),
            ];
            if (! empty($data['password'])) {
                $payload['password'] = $data['password'];
            }
            $user->update($payload);

            $tenantId = (string) tenant('id');
            if ($oldEmail !== $user->email) {
                $this->membership->removeLookup($oldEmail, $tenantId);
            }
            $this->membership->ensureLookup($user->email, $tenantId);

            if (array_key_exists('group_ids', $data)) {
                $this->syncGroups($user, $data['group_ids'] ?? []);
            }

            return $user->refresh();
        });
    }

    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            ConfigGroupUser::query()->where('user_id', $user->id)->delete();
            $this->membership->removeLookup($user->email, (string) tenant('id'));
            $user->delete();
        });
    }

    /** @param  list<int|string>  $groupIds */
    private function syncGroups(User $user, array $groupIds): void
    {
        $ids = collect($groupIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        ConfigGroupUser::query()
            ->where('user_id', $user->id)
            ->when($ids->isNotEmpty(), fn ($q) => $q->whereNotIn('group_id', $ids))
            ->delete();

        foreach ($ids as $groupId) {
            $group = ConfigGroup::query()->find($groupId);
            if (! $group) {
                continue;
            }
            ConfigGroupUser::query()->updateOrCreate(
                ['group_id' => $group->id, 'user_id' => $user->id],
                ['group_code' => $group->code],
            );
        }
    }
}
