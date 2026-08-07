<?php

namespace App\Http\Middleware;

use App\Modules\Config\Services\ConfigService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce SYSCONFIG trustee (C/R/U/D) for a menu code.
 * Usage: middleware('menu.perm:INVENTORY') — action inferred from route name.
 */
class EnsureMenuPermission
{
    public function __construct(
        protected ConfigService $config,
    ) {}

    public function handle(Request $request, Closure $next, string $menuCode): Response
    {
        $user = $request->user();
        if (! $user || ! tenancy()->initialized) {
            abort(403);
        }

        $perms = $this->config->permissionsForUserMenu((int) $user->id, $menuCode);
        $need = $this->neededPermission($request);

        if (! ($perms[$need] ?? false)) {
            abort(403, 'No '.$need.' access for '.$menuCode.'.');
        }

        return $next($request);
    }

    private function neededPermission(Request $request): string
    {
        $action = $request->route()?->getActionMethod() ?? '';

        return match ($action) {
            'create', 'store' => 'create',
            'edit', 'update' => 'update',
            'destroy' => 'delete',
            // Non-resource methods (bulkDestroy, updateStatus, quickUpdate, storeAttachment, …) —
            // fall back to the HTTP verb, never to 'read', so a write request can't be
            // authorized by a read-only permission just because its method name is unmapped.
            default => match ($request->method()) {
                'DELETE' => 'delete',
                'POST' => 'create',
                'PUT', 'PATCH' => 'update',
                default => 'read',
            },
        };
    }
}
