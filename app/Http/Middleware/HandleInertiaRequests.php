<?php

namespace App\Http\Middleware;

use App\Modules\SysConfig\Services\ConfigService;
use App\Services\TenantFeatureService;
use App\Services\TenantMembershipService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = tenancy()->initialized ? $request->user() : null;
        $tenant = tenancy()->initialized ? tenant() : null;

        return [
            ...parent::share($request),
            'auth' => [
                // Users table exists only on tenant DB — never resolve without tenancy.
                'user' => $user,
            ],
            'currentTenant' => $tenant ? [
                'id' => (string) $tenant->getTenantKey(),
                'name' => method_exists($tenant, 'displayName')
                    ? $tenant->displayName()
                    : (string) $tenant->getTenantKey(),
                'plan' => (string) ($tenant->plan ?? 'starter'),
            ] : null,
            'features' => fn () => ($user && tenancy()->initialized)
                ? app(TenantFeatureService::class)->enabledModules()
                : [],
            'tenants' => fn () => ($user)
                ? app(TenantMembershipService::class)
                    ->tenantsForEmail($user->email)
                    ->map(fn ($t) => [
                        'id' => (string) $t->getTenantKey(),
                        'name' => $t->displayName(),
                    ])
                    ->values()
                    ->all()
                : [],
            // ponytail: named navMenus so page props like Config Menus `items` never shadow the sidebar
            'navMenus' => fn () => ($user && tenancy()->initialized)
                ? app(ConfigService::class)->menusForUser((int) $user->id)
                : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
