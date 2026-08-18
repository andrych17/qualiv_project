<?php

namespace App\Modules\Central\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Central\Requests\CentralAdminLoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CentralAdminSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Central/Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(CentralAdminLoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('central.dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('central_admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('central.login');
    }
}
