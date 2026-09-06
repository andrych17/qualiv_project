<?php

namespace App\Http\Middleware;

use App\Modules\Accounting\Services\CompanyContextService;
use App\Modules\SysConfig\Services\ConfigService;
use App\Modules\SysConfig\Services\LocaleService;
use App\Modules\SysConfig\Services\ThemeService;
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
            // CRM_MERGE has no sidebar row (status_code 'I' — see SysConfigSeeder) since
            // it's reached from CrmSubNav, not the sidebar, so it needs its own explicit
            // permission check rather than piggybacking on navMenus. The route's own
            // menu.perm:CRM_MERGE middleware is the authoritative gate; this only decides
            // whether the CrmSubNav tab is worth showing.
            'canMergePartners' => fn () => ($user && tenancy()->initialized)
                ? (bool) (app(ConfigService::class)->permissionsForUserMenu((int) $user->id, 'CRM_MERGE')['read'] ?? false)
                : false,
            'canManageTheme' => fn () => ($user && tenancy()->initialized)
                ? (bool) (app(ConfigService::class)->permissionsForUserMenu((int) $user->id, 'CONFIG_THEME')['read'] ?? false)
                : false,
            // §3K — only computed on an Accounting page (the routeIs guard keeps this a
            // no-op query everywhere else); AppHeader's switcher reads this to render
            // itself, and its own navigation is what keeps every Accounting screen's
            // "current company" agreeing with CompanyContextService's session state.
            'accountingCompanyContext' => fn () => ($user && tenancy()->initialized && $request->routeIs('accounting.*'))
                ? app(CompanyContextService::class)->contextFor($request)
                : null,
            'locale' => fn () => app(LocaleService::class)->resolveLocale($request),
            'availableLocales' => fn () => app(LocaleService::class)->getAvailableLocales(),
            'translations' => fn () => app(LocaleService::class)->getTranslations(
                app(LocaleService::class)->resolveLocale($request)
            ),
            'theme' => fn () => ($user && tenancy()->initialized)
                ? app(ThemeService::class)->getCurrentTheme((int) $user->id)
                : ($request->hasSession() ? ($request->session()->get('theme') ?? ThemeService::DEFAULT_THEME) : ThemeService::DEFAULT_THEME),
            'availableThemes' => fn () => app(ThemeService::class)->getAvailableThemes(),
            'flash' => [
                'success' => fn () => ($s = $request->session()->get('success')) ? __($s) : null,
                'error' => fn () => ($e = $request->session()->get('error')) ? __($e) : null,
                'warning' => fn () => ($w = $request->session()->get('warning')) ? __($w) : null,
                'info' => fn () => ($i = $request->session()->get('info')) ? __($i) : null,
                // One-time reveal of an admin-provisioned password (see ConfigUserService::
                // create/resetPassword) — never persisted, gone after this single request.
                'credentials' => fn () => $request->session()->get('generated_credentials'),
            ],
        ];
    }
}
