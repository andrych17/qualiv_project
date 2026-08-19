<?php

namespace App\Modules\Central\Services;

use App\Models\Tenant;
use App\Modules\Central\Models\CentralTenantAddon;
use Illuminate\Support\Facades\DB;

class CentralTenantService
{
    public function __construct(
        protected CentralAuditLogger $auditLogger,
        protected CentralAccessStatusCache $accessStatusCache,
    ) {}

    /**
     * Creating the Tenant row is what actually triggers stancl's provisioning pipeline
     * (TenantCreated -> CreateDatabase -> CreateModuleSchemas -> MigrateDatabase, wired
     * synchronously in TenancyServiceProvider) — this service doesn't duplicate any of
     * that, it's just the admin-facing entry point onto the existing mechanism.
     */
    public function create(array $data): Tenant
    {
        $tenant = Tenant::create([
            'id' => $this->nextTenantId(),
            'name' => $data['name'],
            'plan' => $data['plan_code'],
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
        ]);

        // Provisioning already ran synchronously above (stancl pipeline) — record the
        // resulting DB reference (CENTRAL_SPECS.md §3B/§4). No pending/provisioning gate
        // in this codebase's MVP; provisioning_status stays at its 'provisioned' default.
        $tenant->update([
            'tenant_db_name' => 'tenant_'.$tenant->getKey(),
            'provisioned_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'tenant_registered',
            entityType: 'tenant',
            entityId: $tenant->getKey(),
            after: $tenant->refresh()->toArray(),
        );

        return $tenant;
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $planChanged = isset($data['plan_code']) && (string) $data['plan_code'] !== (string) $tenant->plan;
        $before = $planChanged ? $tenant->toArray() : [];

        $tenant->update([
            'name' => $data['name'],
            'plan' => $data['plan_code'],
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
        ]);

        // A plan change is a billing-relevant event, not a quiet toggle (CENTRAL_SPECS.md §3C).
        if ($planChanged) {
            $this->auditLogger->log(
                action: 'plan_changed',
                entityType: 'tenant',
                entityId: $tenant->getKey(),
                before: $before,
                after: $tenant->refresh()->toArray(),
            );
        }

        return $tenant->refresh();
    }

    public function addAddon(Tenant $tenant, string $moduleCode, ?float $priceOverride = null): CentralTenantAddon
    {
        return DB::transaction(function () use ($tenant, $moduleCode, $priceOverride) {
            $addon = CentralTenantAddon::query()->create([
                'tenant_id' => $tenant->getKey(),
                'module_code' => strtoupper($moduleCode),
                'added_at' => now(),
                'price_override' => $priceOverride,
                'status' => 'active',
            ]);

            $this->auditLogger->log(
                action: 'addon_added',
                entityType: 'tenant',
                entityId: $tenant->getKey(),
                after: $addon->toArray(),
            );

            return $addon;
        });
    }

    /** Removing an addon is a status flip, never a delete (CENTRAL_SPECS.md §3C). */
    public function removeAddon(CentralTenantAddon $addon): CentralTenantAddon
    {
        return DB::transaction(function () use ($addon) {
            $before = $addon->toArray();
            $addon->update(['status' => 'removed']);

            $this->auditLogger->log(
                action: 'addon_removed',
                entityType: 'tenant',
                entityId: $addon->tenant_id,
                before: $before,
                after: $addon->refresh()->toArray(),
            );

            return $addon;
        });
    }

    /**
     * Exceptional manual override alongside the automatic reactivate-on-payment path
     * (CENTRAL_SPECS.md §3G) — e.g. Simon comping an overdue tenant. Requires a reason, always
     * logged, never a silent toggle.
     */
    public function reactivate(Tenant $tenant, string $reason): Tenant
    {
        return DB::transaction(function () use ($tenant, $reason) {
            $before = $tenant->toArray();

            $tenant->update(['access_status' => 'active']);
            $this->accessStatusCache->invalidate($tenant->getKey());

            $this->auditLogger->log(
                action: 'access_status_changed',
                entityType: 'tenant',
                entityId: $tenant->getKey(),
                before: $before,
                after: [...$tenant->refresh()->toArray(), 'reason' => $reason],
            );

            return $tenant;
        });
    }

    /** Stable, zero-padded string ids (001, 002, ...) — matches the existing tenant_001 convention. */
    private function nextTenantId(): string
    {
        // ponytail: the row lock narrows but doesn't eliminate the id race (single-admin MVP,
        // so the window is effectively zero); the tenants.id unique constraint is the real
        // backstop — a collision surfaces as a 500 rather than silent data corruption.
        return DB::transaction(function (): string {
            $max = (int) Tenant::query()->orderByDesc('id')->lockForUpdate()->value('id') ?? 0;

            return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
        });
    }
}
