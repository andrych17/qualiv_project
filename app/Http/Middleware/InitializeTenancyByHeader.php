<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant resolution for token-based API requests (LEGAL_SPECS.md §3M mobile surface) — there's
 * no session to be login-bound to (CLAUDE.md §4), so the client sends the tenant it logged
 * into (POST /api/v1/auth/login returns it) back on every request via `X-Tenant-Id`. Mirrors
 * InitializeTenancyBySession's shape exactly, just reading a header instead of the session.
 *
 * The header alone grants nothing — it only selects which tenant DB the bearer token is looked
 * up against next (auth:sanctum runs after this, see bootstrap/app.php's priority list). A
 * token issued in tenant A simply won't be found in tenant B's `personal_access_tokens` table,
 * so a wrong/forged header just fails auth, exactly as safe as the web session equivalent.
 */
class InitializeTenancyByHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized) {
            $tenantId = $request->header('X-Tenant-Id');

            $tenant = $tenantId !== null && $tenantId !== '' ? Tenant::query()->find($tenantId) : null;

            if (! $tenant) {
                abort(401, 'Missing or unknown X-Tenant-Id header.');
            }

            tenancy()->initialize($tenant);
        }

        return $next($request);
    }
}
