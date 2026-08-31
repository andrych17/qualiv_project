<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\CapacityPlan;
use App\Modules\SysConfig\Services\ConfigService;

/**
 * PP_SPECS.md §3F — flat CRUD for a `pp_capacity_plans` row, plus the load%/overload
 * calculation. Rough-cut only (Phase 1, "load vs. available is informational" — this spec's own
 * §3F Rules/Logic): required/available hours are planner-entered rather than auto-computed,
 * since neither of the two things the spec names as the automatic source exists yet —
 * `MesService`'s routing/recipe-phase standard times (MES isn't built) and a total-available-
 * hours-in-a-period aggregator on Schedule's `AvailabilityService` (which only answers "is this
 * exact slot free right now?", not "how many hours are free in this range?").
 */
class CapacityPlanService
{
    public function __construct(protected ConfigService $config) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): CapacityPlan
    {
        return CapacityPlan::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(CapacityPlan $plan, array $data): CapacityPlan
    {
        $plan->update($this->attributes($data));

        return $plan->refresh();
    }

    public function delete(CapacityPlan $plan): void
    {
        $plan->delete();
    }

    public function loadPct(CapacityPlan $plan): float
    {
        $available = (float) $plan->available_hours;
        if ($available <= 0) {
            return 0.0;
        }

        return round((float) $plan->required_hours / $available * 100, 1);
    }

    public function isOverloaded(CapacityPlan $plan): bool
    {
        $threshold = (float) ($this->config->get('PP', 'CAPACITY_OVERLOAD_THRESHOLD_PCT') ?? 100);

        return $this->loadPct($plan) > $threshold;
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'resource_group_id' => $data['resource_group_id'] ?? null,
            'resource_type' => $data['resource_group_id'] ?? null ? null : ($data['resource_type'] ?? null),
            'resource_ref_id' => $data['resource_group_id'] ?? null ? null : ($data['resource_ref_id'] ?? null),
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'required_hours' => $data['required_hours'],
            'available_hours' => $data['available_hours'],
        ];
    }
}
