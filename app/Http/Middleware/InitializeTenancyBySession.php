<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login-bound tenancy: after login, session holds tenant_id; re-init on each request.
 */
class InitializeTenancyBySession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized) {
            $tenantId = $request->session()->get('tenant_id');

            // Do not require is_string — serializers may widen values.
            if ($tenantId !== null && $tenantId !== '') {
                $tenant = Tenant::query()->find((string) $tenantId);

                if ($tenant) {
                    try {
                        tenancy()->initialize($tenant);
                    } catch (\Throwable $e) {
                        // Tenant DB might be missing or corrupted.
                        // Allow falling through to drop orphan session.
                    }
                }
            }
        }

        // Users live in tenant DB. Drop orphan login keys without Auth::logout()
        // (logout() would call user() and query central `users`).
        if (! tenancy()->initialized) {
            $guard = Auth::guard('web');
            $loginKey = $guard->getName();

            if ($request->session()->has($loginKey)) {
                $request->session()->forget([
                    $loginKey,
                    'password_hash_'.$loginKey,
                    'tenant_id',
                ]);
                $guard->forgetUser();
            }
        }

        return $next($request);
    }
}
