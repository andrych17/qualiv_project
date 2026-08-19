<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Central membership + tenant switch (netapp1 app-switcher analogue).
 * Users still live in each tenant DB; lookup maps email → tenant ids.
 */
class TenantMembershipService
{
    /** @return Collection<int, Tenant> */
    public function tenantsForEmail(string $email): Collection
    {
        $email = Str::lower($email);
        $ids = TenantUserLookup::query()
            ->where('email', $email)
            ->pluck('tenant_id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Tenant::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    public function emailBelongsToTenant(string $email, string $tenantId): bool
    {
        return TenantUserLookup::query()
            ->where('email', Str::lower($email))
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    public function ensureLookup(string $email, string $tenantId): void
    {
        TenantUserLookup::query()->updateOrCreate(
            [
                'email' => Str::lower($email),
                'tenant_id' => $tenantId,
            ],
            [],
        );
    }

    public function removeLookup(string $email, string $tenantId): void
    {
        TenantUserLookup::query()
            ->where('email', Str::lower($email))
            ->where('tenant_id', $tenantId)
            ->delete();
    }

    /**
     * Switch active tenant for an already-authenticated email.
     * Logs into the matching user row inside the target tenant DB.
     */
    public function switchTo(string $tenantId, string $email): Tenant
    {
        $email = Str::lower($email);

        if (! $this->emailBelongsToTenant($email, $tenantId)) {
            abort(403, 'Not a member of this tenant.');
        }

        $tenant = Tenant::query()->findOrFail($tenantId);

        Auth::logout();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        tenancy()->initialize($tenant);

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            tenancy()->end();
            abort(403, 'User missing in target tenant.');
        }

        if (! $user->is_active) {
            tenancy()->end();
            abort(403, 'This account has been deactivated in the target tenant.');
        }

        Auth::login($user);
        session()->put('tenant_id', (string) $tenant->getTenantKey());
        session()->regenerate();

        return $tenant;
    }
}
