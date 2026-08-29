<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Tenant;
use App\Services\CentralAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Fast tenant discovery by email for adaptive login.
     */
    public function lookup(Request $request, CentralAuthService $authService): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $tenants = $authService->getTenantsForEmail($request->string('email')->toString());

        return response()->json([
            'tenants' => $tenants->map(fn (Tenant $t) => [
                'id' => (string) $t->getTenantKey(),
                'name' => $t->displayName(),
            ])->values(),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Re-assert after regenerate so priority/auth never sees a login without tenant.
        if ($tenantId = tenant('id')) {
            $request->session()->put('tenant_id', (string) $tenantId);
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $request->session()->forget('tenant_id');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
