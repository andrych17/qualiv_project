<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Plan → module flags for the current tenant (CLAUDE.md: first-class Core concept).
 * Plan lives on central tenants.plan; module matrix in config/tenant_modules.php.
 */
class TenantFeatureService
{
    public function plan(): string
    {
        if (! tenancy()->initialized) {
            return 'none';
        }

        $tenant = tenant();

        return (string) ($tenant?->plan ?: 'starter');
    }

    /** @return list<string> */
    public function enabledModules(): array
    {
        $plans = Config::get('tenant_modules.plans', []);
        $plan = $this->plan();

        return array_values($plans[$plan] ?? $plans['starter'] ?? []);
    }

    public function enabled(string $moduleCode): bool
    {
        return in_array(strtoupper($moduleCode), $this->enabledModules(), true);
    }
}
