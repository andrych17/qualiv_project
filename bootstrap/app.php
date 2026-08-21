<?php

use App\Http\Middleware\EnsureMenuPermission;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureTenantStanding;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InitializeTenancyBySession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // §3G inbound webhooks: no `web` group (no session/CSRF for an external caller) and
        // no `api` group (would need a `RateLimiter::for('api', ...)` this app has never
        // defined) — registered bare, with only the middleware each route explicitly needs.
        then: function () {
            require app_path('Modules/WNE/Routes/api.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // Must run AFTER session is started and BEFORE Authenticate loads User.
        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: InitializeTenancyBySession::class,
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
