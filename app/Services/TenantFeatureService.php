<?php

namespace App\Services;

use App\Modules\Central\Models\CentralPlanModule;
use App\Modules\Central\Services\CentralEntitlementService;
use App\Modules\SysConfig\Services\TenantModuleService;
use Illuminate\Support\Facades\Config;

/**
 * Plan → module flags for the current tenant (CLAUDE.md: first-class Core concept).
 * Plan lives on central tenants.plan; module matrix in config/tenant_modules.php.
 */
class TenantFeatureService
{
    /** @var list<string>|null */
    protected ?array $memoEnabled = null;

    public function clearCache(): void
    {
        $this->memoEnabled = null;
    }

    public function plan(): string
    {
        if (! tenancy()->initialized) {
            return 'none';
        }

        $tenant = tenant();

        return (string) ($tenant?->plan ?: 'starter');
    }

    /**
     * Data-driven entitlement (central_plan_modules + central_tenant_addons,
     * CENTRAL_SPECS.md §3C) is the source of truth. Falls back to the static
     * config/tenant_modules.php matrix only when the plan has no rows yet in
     * central_plan_modules — i.e. before that data has been seeded — so nothing
     * breaks pre-migration/pre-seed.
     *
     * @return list<string>
     */
    public function enabledModules(): array
    {
        if ($this->memoEnabled !== null) {
            return $this->memoEnabled;
        }

        if (tenancy()->initialized) {
            $tenantId = (string) tenant()->getTenantKey();
            $plan = $this->plan();

            if (CentralPlanModule::query()->where('plan_code', $plan)->exists()) {
                return $this->memoEnabled = app(CentralEntitlementService::class)->entitledModules($tenantId);
            }

            $plans = Config::get('tenant_modules.plans', []);
            $planModules = $plans[$plan] ?? $plans['starter'] ?? [];
            $addons = app(CentralEntitlementService::class)->addonsForTenant($tenantId);

            return $this->memoEnabled = array_values(array_unique([...$planModules, ...$addons]));
        }

        $plans = Config::get('tenant_modules.plans', []);
        $plan = $this->plan();

        return $this->memoEnabled = array_values($plans[$plan] ?? $plans['starter'] ?? []);
    }

    public function entitled(string $moduleCode): bool
    {
        return in_array(strtoupper($moduleCode), $this->enabledModules(), true);
    }

    /**
     * Effective visibility: entitled (central plan) AND tenant_modules.is_active
     * (opt-out default — missing row counts as active). SYSCONFIG_SPECS.md §3A.
     */
    public function enabled(string $moduleCode): bool
    {
        if (! $this->entitled($moduleCode)) {
            return false;
        }

        return app(TenantModuleService::class)->isActive($moduleCode);
    }
}
