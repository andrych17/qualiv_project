<?php

namespace App\Http\Middleware;

use App\Services\TenantFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate routes by tenant plan module flags (CLAUDE.md §4).
 * Usage: middleware('module:LEGAL')
 */
class EnsureModuleEnabled
{
    public function __construct(
        protected TenantFeatureService $features,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleCode): Response
    {
        if (! tenancy()->initialized || ! $this->features->enabled($moduleCode)) {
            abort(403, 'Module '.$moduleCode.' is not enabled for this tenant.');
        }

        return $next($request);
    }
}
