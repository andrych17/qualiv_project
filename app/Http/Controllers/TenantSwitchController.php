<?php

namespace App\Http\Controllers;

use App\Services\TenantMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TenantSwitchController extends Controller
{
    public function __invoke(Request $request, TenantMembershipService $membership): RedirectResponse
    {
        $request->validate([
            'tenant_id' => 'required|string',
        ]);

        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $membership->switchTo($request->string('tenant_id')->toString(), $user->email);

        return redirect()->route('dashboard')
            ->with('success', 'Switched tenant.');
    }
}
