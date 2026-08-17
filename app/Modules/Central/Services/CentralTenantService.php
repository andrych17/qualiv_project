<?php

namespace App\Modules\Central\Services;

use App\Models\Tenant;

class CentralTenantService
{
    /**
     * Creating the Tenant row is what actually triggers stancl's provisioning pipeline
     * (TenantCreated -> CreateDatabase -> CreateModuleSchemas -> MigrateDatabase, wired
     * synchronously in TenancyServiceProvider) — this service doesn't duplicate any of
     * that, it's just the admin-facing entry point onto the existing mechanism.
     */
    public function create(array $data): Tenant
    {
        return Tenant::create([
            'id' => $this->nextTenantId(),
            'name' => $data['name'],
            'plan' => $data['plan_code'],
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
        ]);
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->update([
            'name' => $data['name'],
            'plan' => $data['plan_code'],
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
        ]);

        return $tenant->refresh();
    }

    /** Stable, zero-padded string ids (001, 002, ...) — matches the existing tenant_001 convention. */
    private function nextTenantId(): string
    {
        $max = Tenant::query()
            ->get(['id'])
            ->map(fn (Tenant $t) => (int) $t->getKey())
            ->max() ?? 0;

        return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
