<?php

namespace App\Modules\SysConfig\Services;

use App\Models\User;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use App\Services\TenantMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConfigUserService
{
    public function __construct(
        protected TenantMembershipService $membership,
    ) {}

    /**
     * Admin provisions the account; we generate the password rather than let the admin
     * choose or see it typed in. Admin relays the returned plaintext to the employee
     * out-of-band (email/text) — it is never stored or shown again after this call.
     *
     * @return array{user: User, password: string}
     */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $plainPassword = Str::password(16);

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'password' => $plainPassword,
                'email_verified_at' => now(),
            ]);

            $tenantId = (string) tenant('id');
            $this->membership->ensureLookup($user->email, $tenantId);

            if (! empty($data['group_ids'])) {
                $this->syncGroups($user, $data['group_ids']);
            }

            return ['user' => $user, 'password' => $plainPassword];
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $oldEmail = $user->email;
            $user->update([
                'name' => $data['name'],
                'email' => strtolower($data['email']),
            ]);

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

    /**
     * Same reasoning as create(): generate rather than let the admin type a new password.
     */
    public function resetPassword(User $user): string
    {
        $plainPassword = Str::password(16);

        $user->update(['password' => $plainPassword]);

        return $plainPassword;
    }

    /**
     * Deactivate rather than delete: User rows are referenced by created_by/approved_by
     * FKs across other modules, so removing the row would orphan audit history. Login is
     * blocked via the is_active flag (see LoginRequest, TenantAwareUserProvider,
     * TenantMembershipService) — group memberships and the central lookup are left intact
     * so re-activating restores prior access without redoing setup.
     */
    public function deactivate(User $user): void
    {
        $user->update(['is_active' => false]);
    }

    public function activate(User $user): void
    {
        $user->update(['is_active' => true]);
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
