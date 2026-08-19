<?php

namespace App\Http\Middleware;

use App\Modules\Central\Services\CentralAccessStatusCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft-cutoff enforcement (CENTRAL_SPECS.md §3G/§5): blocks state-changing requests once
 * CENTRAL has flipped a tenant to read_only. Never touches tenant data — a blocked request
 * lands on the tenant-facing Billing screen with a calm, explanatory message, the direct
 * path back to `active` (§5 asks for exactly this link).
 *
 * Registered globally on the `web` middleware group (bootstrap/app.php), so it runs ahead of
 * every tenant route without touching each module's own route file. The tenant-facing Billing
 * screen's submit action (§3H) is the one deliberate exception — it's the only path back to
 * `active` — exempted here by route name since the global group can't otherwise skip it.
 */
class EnsureTenantStanding
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const EXEMPT_ROUTE_NAMES = ['billing.payments.store'];

    private const MESSAGE = 'Your subscription is past due. You can still view your data, but changes are paused until payment is confirmed.';

    public function __construct(
        protected CentralAccessStatusCache $accessStatusCache,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! in_array($request->method(), self::SAFE_METHODS, true)
            && tenancy()->initialized
            && ! in_array($request->route()?->getName(), self::EXEMPT_ROUTE_NAMES, true)
            && $this->accessStatusCache->get(tenant()->getTenantKey()) === 'read_only'
        ) {
            if ($request->expectsJson()) {
                abort(403, self::MESSAGE);
            }

            // §5's "direct link to §3H's Billing screen" — Inertia follows the redirect and
            // the global flash toast shows the message once they land.
            return redirect()->route('billing.index')->with('error', self::MESSAGE);
        }

        return $next($request);
    }
}
