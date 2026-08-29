<?php

use App\Http\Middleware\EnsureMenuPermission;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureTenantStanding;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InitializeTenancyByHeader;
use App\Http\Middleware\InitializeTenancyBySession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // LEGAL_SPECS.md §3M mobile surface + its POST /api/v1/auth/login — bearer-token API,
        // unlike WNE's bare webhook routes below this needs real rate limiting (throttleApi()).
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // §3G inbound webhooks: no `web` group (no session/CSRF for an external caller) and
        // deliberately outside the `api` group too (a webhook has no bearer token to throttle
        // per-user, and the tenant travels in the URL, not a header) — registered bare, with
        // only the middleware each route explicitly needs.
        then: function () {
            require app_path('Modules/WNE/Routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->throttleApi();

        // Must run AFTER session is started and BEFORE Authenticate loads User.
        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: InitializeTenancyBySession::class,
        );

        // No session on API requests to key off — must still land before auth:sanctum's guard
        // resolves a token (and before SubstituteBindings queries a tenant-schema model), so
        // it's prioritized directly against the same anchor InitializeTenancyBySession uses.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: InitializeTenancyByHeader::class,
        );

        $middleware->web(append: [
            InitializeTenancyBySession::class,
            // CENTRAL_SPECS.md §3G/§5 — soft-cutoff enforcement across every tenant module,
            // registered globally rather than per-route so no module has to remember to add it.
            EnsureTenantStanding::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsurePasswordIsChanged::class,
        ]);

        $middleware->alias([
            'menu.perm' => EnsureMenuPermission::class,
            'module' => EnsureModuleEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
