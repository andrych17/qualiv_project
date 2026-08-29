<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MobileLoginRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CentralAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bearer-token issuance for API clients (LEGAL_SPECS.md §3M mobile surface) — the
 * stateless-request counterpart to CentralAuthService::authenticate() (used by the web
 * session login). Credentials are checked manually (Hash::check, not Auth::attempt): the
 * SessionGuard behind Auth::attempt() needs a booted session to log a user into, and
 * routes/api.php carries no `web` middleware / session on purpose (CLAUDE.md §4 — no
 * cookie-based auth here, bearer tokens only).
 *
 * Platform-level (Core) despite the field-visit ability it issues — auth/tenant-selection
 * isn't Legal's to own, and any future vertical's mobile client reuses this same endpoint;
 * `AuthenticatedSessionController::lookup()` (web's adaptive-login tenant discovery) is reused
 * as-is for the tenant-picker step, see routes/api.php.
 */
class AuthController extends Controller
{
    public function __construct(
        protected CentralAuthService $central,
    ) {}

    public function login(MobileLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $email = Str::lower(trim($data['email']));
        $throttleKey = $this->central->throttleKey($email, $request->ip());

        $this->central->ensureIsNotRateLimited($throttleKey, $request);

        $tenants = $this->central->getTenantsForEmail($email);

        if ($tenants->isEmpty()) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $tenant = $this->resolveTenant($tenants, $data['tenant_id'] ?? null, $throttleKey);

        tenancy()->initialize($tenant);

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages(['password' => trans('auth.failed')]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $deviceName = $data['device_name'] ?? 'mobile';
        $token = $user->createToken($deviceName, ['legal:field-visits'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'tenant_id' => (string) $tenant->getTenantKey(),
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /** Revokes only the token used for this request, not every device the user is on. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /** @param  Collection<int, Tenant>  $tenants */
    private function resolveTenant(Collection $tenants, ?string $tenantId, string $throttleKey): Tenant
    {
        if ($tenantId !== null && $tenantId !== '') {
            $tenant = $tenants->first(fn (Tenant $t) => (string) $t->getTenantKey() === $tenantId);
            if (! $tenant) {
                RateLimiter::hit($throttleKey);
                throw ValidationException::withMessages([
                    'tenant_id' => 'You do not have access to the selected company/tenant.',
                ]);
            }

            return $tenant;
        }

        if ($tenants->count() === 1) {
            return $tenants->first();
        }

        RateLimiter::hit($throttleKey);
        throw ValidationException::withMessages([
            'tenant_id' => 'Please select a company/tenant to login.',
        ]);
    }
}
