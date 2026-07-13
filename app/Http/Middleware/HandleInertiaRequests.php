<?php

namespace App\Http\Middleware;

use App\Modules\Config\Services\ConfigService;
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

        return [
            ...parent::share($request),
            'auth' => [
                // Users table exists only on tenant DB — never resolve without tenancy.
                'user' => $user,
            ],
            'menus' => fn () => ($user && tenancy()->initialized)
                ? app(ConfigService::class)->menusForUser((int) $user->id)
                : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
