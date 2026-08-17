<?php

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\CentralPlan;

class CentralPlanService
{
    public function create(array $data): CentralPlan
    {
        return CentralPlan::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_monthly' => $data['price_monthly'] ?? 0,
            'currency' => $data['currency'] ?? 'IDR',
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function update(CentralPlan $plan, array $data): CentralPlan
    {
        $plan->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_monthly' => $data['price_monthly'] ?? $plan->price_monthly,
            'currency' => $data['currency'] ?? $plan->currency,
            'is_active' => $data['is_active'] ?? $plan->is_active,
        ]);

        return $plan->refresh();
    }

    /**
     * "Delete" from the admin's point of view is a deactivation, never a row delete —
     * blocks new tenants from being assigned this plan but never affects tenants already
     * on it (CENTRAL_SPECS.md §3D), same non-destructive pattern as every other lookup
     * table in this platform.
     */
    public function deactivate(CentralPlan $plan): CentralPlan
    {
        $plan->update(['is_active' => false]);

        return $plan->refresh();
    }
}
