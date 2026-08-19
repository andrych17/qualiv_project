<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-generated passwords (ConfigUserService::create/resetPassword) are temporary —
 * block every other route until the employee sets their own.
 */
class EnsurePasswordIsChanged
{
    private const EXEMPT_ROUTES = ['password.update', 'profile.edit', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        return redirect()->route('profile.edit')
            ->with('error', 'Set a new password before continuing.');
    }
}
