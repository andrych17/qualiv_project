<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant resolution for inbound requests with no session to be login-bound to (external
 * webhooks/callbacks) — the tenant id travels in the URL itself instead, the one deliberate
 * exception to CLAUDE.md §4's "login-bound, not domain/subdomain" rule. Mirrors
 * InitializeTenancyBySession's shape exactly, just reading a route parameter instead of the
 * session.
 */
class InitializeTenancyByRouteParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized) {
            $tenantId = $request->route('tenant');
            $tenant = $tenantId !== null ? Tenant::query()->find((string) $tenantId) : null;

            if (! $tenant) {
                abort(404);
            }

            tenancy()->initialize($tenant);

            // Laravel binds controller arguments positionally once class-type dependencies
            // (e.g. Request) are spliced back in — leaving 'tenant' in the route's parameter
            // list shifts every parameter after it by one slot (e.g. a `{token}` after
            // `{tenant}` in the URI would arrive in the controller as the tenant id, not the
            // token). Forgetting it here, once consumed, is what stancl/tenancy's own
            // path-based middleware does for the same reason.
            $request->route()->forgetParameter('tenant');
        }

        return $next($request);
    }
}
