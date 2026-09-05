<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'multitenant' => \App\Http\Middleware\InitializeTenancy::class,
            'module.active' => \App\Http\Middleware\EnsureModuleIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (! in_array($status, [403, 404, 500, 503], true)) {
                return $response;
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $startSession = app(StartSession::class);

            return $startSession->handle($request, function ($req) use ($status) {
                return inertia('Errors/Error', ['status' => $status])
                    ->toResponse($req)
                    ->setStatusCode($status);
            });
        });
    })->create();
