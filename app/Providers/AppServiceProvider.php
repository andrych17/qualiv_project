<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Modules\Legal\Contracts\CaseCodeGenerator;
use App\Modules\Legal\Services\PrefixedCaseCodeGenerator;
use Illuminate\Support\Facades\Auth;
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
        Vite::prefetch(concurrency: 3);

        Auth::provider('eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });
    }
}
