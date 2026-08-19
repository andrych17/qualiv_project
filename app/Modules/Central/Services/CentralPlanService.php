<?php

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\CentralPlan;
use App\Modules\Central\Models\CentralPlanModule;
use Illuminate\Support\Facades\DB;

class CentralPlanService
{
    public function __construct(
        protected CentralAuditLogger $auditLogger,
    ) {}

    public function create(array $data): CentralPlan
    {
        return DB::transaction(function () use ($data) {
            $plan = CentralPlan::query()->create([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price_monthly' => $data['price_monthly'] ?? 0,
                'price_annual' => $data['price_annual'] ?? null,
                'billing_cycle' => $data['billing_cycle'] ?? 'monthly',
                'currency' => $data['currency'] ?? 'IDR',
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncModules($plan, $data['module_codes'] ?? []);

            $this->auditLogger->log(
                action: 'plan_changed',
                entityType: 'plan',
                entityId: $plan->code,
                after: $this->snapshot($plan),
            );

            return $plan;
        });
    }

    public function update(CentralPlan $plan, array $data): CentralPlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $before = $this->snapshot($plan);

            $plan->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price_monthly' => $data['price_monthly'] ?? $plan->price_monthly,
                'price_annual' => $data['price_annual'] ?? $plan->price_annual,
                'billing_cycle' => $data['billing_cycle'] ?? $plan->billing_cycle,
                'currency' => $data['currency'] ?? $plan->currency,
                'is_active' => $data['is_active'] ?? $plan->is_active,
            ]);

            if (array_key_exists('module_codes', $data)) {
                $this->syncModules($plan, $data['module_codes'] ?? []);
            }

            $plan->refresh();

            $this->auditLogger->log(
                action: 'plan_changed',
                entityType: 'plan',
                entityId: $plan->code,
                before: $before,
                after: $this->snapshot($plan),
            );

            return $plan;
        });
    }

    /**
     * "Delete" from the admin's point of view is a deactivation, never a row delete —
     * blocks new tenants from being assigned this plan but never affects tenants already
     * on it (CENTRAL_SPECS.md §3D), same non-destructive pattern as every other lookup
     * table in this platform.
     */
    public function deactivate(CentralPlan $plan): CentralPlan
    {
        $before = $this->snapshot($plan);
        $plan->update(['is_active' => false]);
        $plan->refresh();

        $this->auditLogger->log(
            action: 'plan_changed',
            entityType: 'plan',
            entityId: $plan->code,
            before: $before,
            after: $this->snapshot($plan),
        );

        return $plan;
    }

    /** @param list<string> $moduleCodes */
    private function syncModules(CentralPlan $plan, array $moduleCodes): void
    {
        CentralPlanModule::query()->where('plan_code', $plan->code)->delete();

        foreach (array_unique(array_map('strtoupper', $moduleCodes)) as $moduleCode) {
            CentralPlanModule::query()->create([
                'plan_code' => $plan->code,
                'module_code' => $moduleCode,
            ]);
        }
    }

    private function snapshot(CentralPlan $plan): array
    {
        return [
            ...$plan->toArray(),
            'module_codes' => $plan->modules()->pluck('module_code')->values()->all(),
        ];
    }
}
