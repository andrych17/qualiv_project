<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Models\User;
use App\Modules\Legal\Contracts\CaseCodeGenerator;
use App\Modules\Legal\Models\LegalCase;
use App\Modules\Legal\Services\PrefixedCaseCodeGenerator;
use App\Services\AsyncSearchRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CaseCodeGenerator::class, PrefixedCaseCodeGenerator::class);
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        Auth::provider('eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

        // Register default searchable entities with 50 limit cap
        AsyncSearchRegistry::register(
            'user',
            User::class,
            ['name', 'email'],
            'name',
            'email',
            fn () => 'User',
            queryCallback: null,
            filterable: [],
            menuCode: 'CONFIG_USERS',
        );

        AsyncSearchRegistry::register(
            'legal_case',
            LegalCase::class,
            ['code', 'title'],
            fn ($c) => "{$c->code} — {$c->title}",
            fn ($c) => "Status: {$c->status}",
            'status',
            queryCallback: null,
            filterable: [],
            menuCode: 'LEGAL',
        );
    }
}
