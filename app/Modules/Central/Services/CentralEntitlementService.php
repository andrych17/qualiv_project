<?php

namespace App\Modules\Central\Services;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralPlanModule;
use App\Modules\Central\Models\CentralTenantAddon;

/**
 * Effective entitlement = union of the tenant's plan's default modules + their active
 * à la carte addons (CENTRAL_SPECS.md §3C). This is the hard ceiling; SYSCONFIG.tenant_modules
 * can only narrow it, never widen it.
 */
class CentralEntitlementService
{
    /** @return list<string> */
    public function modulesForPlan(string $planCode): array
    {
        return CentralPlanModule::query()
            ->where('plan_code', $planCode)
            ->pluck('module_code')
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function addonsForTenant(string $tenantId): array
    {
        return CentralTenantAddon::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->pluck('module_code')
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function entitledModules(string $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            return [];
        }

        $planModules = $this->modulesForPlan((string) $tenant->plan);
        $addonModules = $this->addonsForTenant($tenantId);

        return array_values(array_unique([...$planModules, ...$addonModules]));
    }

    public function isEntitled(string $tenantId, string $moduleCode): bool
    {
        return in_array(strtoupper($moduleCode), array_map('strtoupper', $this->entitledModules($tenantId)), true);
    }
}
