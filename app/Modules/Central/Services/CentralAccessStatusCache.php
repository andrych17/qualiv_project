<?php

namespace App\Modules\Central\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * `EnsureTenantStanding` runs on every state-changing request across every tenant module, so
 * the resolved `access_status` is cached (CENTRAL_SPECS.md §5's exact key), invalidated the
 * moment a payment is confirmed or the dunning cutoff job changes it — same "cache the
 * resolved value, invalidate on write" pattern as SYSCONFIG.config_consts / CUSTOMFIELDS.field_defs.
 */
class CentralAccessStatusCache
{
    private const TTL_SECONDS = 300;

    public function get(string $tenantId): string
    {
        return Cache::remember(
            self::key($tenantId),
            self::TTL_SECONDS,
            fn () => (string) (Tenant::query()->find($tenantId)?->access_status ?? 'active'),
        );
    }

    public function invalidate(string $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }

    /** CENTRAL_SPECS.md §5's exact cache key format. */
    public static function key(string $tenantId): string
    {
        return "central:tenant:{$tenantId}:access_status";
    }
}
