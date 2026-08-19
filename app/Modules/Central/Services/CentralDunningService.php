<?php

namespace App\Modules\Central\Services;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralDunningPolicy;
use RuntimeException;

/**
 * Most-specific-wins policy resolution (CENTRAL_SPECS.md §3G): a tenant's own negotiated
 * terms override their plan's policy, which overrides the platform default. Mirrors the same
 * override-ladder idea as SYSCONFIG.config_consts's two-tier precedence, one layer up.
 */
class CentralDunningService
{
    public function resolvePolicyFor(Tenant $tenant): CentralDunningPolicy
    {
        $policy = CentralDunningPolicy::query()
            ->where('scope_type', 'tenant')
            ->where('scope_id', $tenant->getKey())
            ->first()
            ?? CentralDunningPolicy::query()
                ->where('scope_type', 'plan')
                ->where('scope_id', (string) $tenant->plan)
                ->first()
            ?? CentralDunningPolicy::query()
                ->where('scope_type', 'platform_default')
                ->first();

        if (! $policy) {
            throw new RuntimeException(
                'No dunning policy resolved for tenant '.$tenant->getKey().
                ' — seed a platform_default policy in central_dunning_policies.',
            );
        }

        return $policy;
    }
}
