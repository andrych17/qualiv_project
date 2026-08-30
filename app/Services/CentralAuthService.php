<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CentralAuthService
{
    /**
     * Get all active tenants associated with the given email from the central lookup table.
     *
     * @return Collection<int, Tenant>
     */
    public function getTenantsForEmail(string $email): Collection
    {
        $normalizedEmail = Str::lower(trim($email));

        $tenantIds = TenantUserLookup::query()
            ->where('email', $normalizedEmail)
            ->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            return collect();
        }

        return Tenant::query()
            ->whereIn('id', $tenantIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * Authenticate user into the target tenant database.
     *
     * @param  array{email: string, password: string, tenant_id?: ?string}  $credentials
     *
     * @throws ValidationException
     */
    public function authenticate(array $credentials, bool $remember = false, ?Request $request = null): Authenticatable
    {
        $throttleKey = $this->throttleKey($credentials['email'], $request?->ip() ?? '127.0.0.1');
        $this->ensureIsNotRateLimited($throttleKey, $request);

        $email = Str::lower(trim($credentials['email']));
        $lookups = TenantUserLookup::query()->where('email', $email)->orderBy('tenant_id')->get();

        if ($lookups->isEmpty()) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $requestedTenantId = isset($credentials['tenant_id']) && $credentials['tenant_id'] !== ''
            ? (string) $credentials['tenant_id']
            : null;

        $targetLookups = $requestedTenantId !== null
            ? $lookups->where('tenant_id', $requestedTenantId)
            : $lookups;

        if ($targetLookups->isEmpty()) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                'tenant_id' => 'You do not have access to the selected company/tenant.',
            ]);
        }

        $hasDeactivatedAccount = false;

        foreach ($targetLookups as $lookup) {
            $tenant = Tenant::query()->find($lookup->tenant_id);
            if (! $tenant) {
                continue;
            }

            tenancy()->initialize($tenant);

            if (Auth::attempt(['email' => $email, 'password' => $credentials['password']], $remember)) {
                /** @var User $user */
                $user = Auth::user();

                if ($user->is_active) {
                    if ($request) {
                        $request->session()->put('tenant_id', (string) $tenant->getTenantKey());
                    }
                    RateLimiter::clear($throttleKey);
                    return $user;
                }

                $hasDeactivatedAccount = true;
                Auth::logout();
            }

            tenancy()->end();
        }

        RateLimiter::hit($throttleKey);

        if ($hasDeactivatedAccount) {
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        throw ValidationException::withMessages([
            'password' => trans('auth.failed'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(string $throttleKey, ?Request $request = null): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return;
        }

        if ($request) {
            event(new Lockout($request));
        }

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower(trim($email)).'|'.$ip);
    }
}
