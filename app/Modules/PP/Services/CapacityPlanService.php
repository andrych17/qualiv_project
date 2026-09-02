<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\CapacityPlan;
use App\Modules\PP\Models\PpException;
use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroupMember;
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
    public function __construct(protected ConfigService $config, protected PpExceptionService $exceptions) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): CapacityPlan
    {
        $plan = CapacityPlan::query()->create($this->attributes($data));
        $this->checkOverload($plan);

        return $plan;
    }

    /** @param  array<string, mixed>  $data */
    public function update(CapacityPlan $plan, array $data): CapacityPlan
    {
        $plan->update($this->attributes($data));
        $plan->refresh();
        $this->checkOverload($plan);

        return $plan;
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

    /**
     * PP_SPECS.md §3G — "not five separate engines": the dimension and its UoM are derived from
     * the same `pp_resources.type`/`uom_code` this row already points at, never stored on
     * `pp_capacity_plans` itself. `machine` stays MES-informational (no MES yet); `warehouse`
     * reads as `storage` per §3E's "warehouse-as-capacity" framing.
     *
     * @return array{dimension: string, unit: string}
     */
    public function dimensionInfo(CapacityPlan $plan): array
    {
        if ($plan->resource_type === CapacityPlan::RESOURCE_TYPE_PP_RESOURCE && $plan->resource_ref_id) {
            $resource = Resource::query()->find($plan->resource_ref_id);

            return $resource ? $this->resourceDimensionInfo($resource) : ['dimension' => 'unclassified', 'unit' => 'hr'];
        }

        if (in_array($plan->resource_type, [CapacityPlan::RESOURCE_TYPE_MES_WORK_CENTER, CapacityPlan::RESOURCE_TYPE_MES_MACHINE], true)) {
            return ['dimension' => 'machine', 'unit' => 'hr'];
        }

        if ($plan->resource_group_id) {
            return $this->groupDimensionInfo($plan->resource_group_id);
        }

        return ['dimension' => 'unclassified', 'unit' => 'hr'];
    }

    /**
     * PP_SPECS.md §3G's own mockup — one OK/OVER status per dimension, worst-case (not an
     * aggregated load %: summing required/available across rows is meaningless once two tank
     * rows carry different `uom_code`, and averaging per-row percentages double-counts the
     * ratio-of-averages error). Baseline only (§3N what-if scenarios never enter this rollup).
     * `labor` and `material` are appended by the caller as "not tracked yet" — neither has a
     * data source yet (no HCM capacity publisher; §3L's material check is warehouse-scoped and
     * PP carries no warehouse on a planned order).
     *
     * @return array<string, array{dimension: string, status: string, worst_label: ?string, worst_load_pct: ?float}>
     */
    public function dimensionRollup(): array
    {
        $rows = [];

        foreach (CapacityPlan::query()->baseline()->with('resourceGroup:id,name')->get() as $plan) {
            $dimension = $this->dimensionInfo($plan)['dimension'];
            $loadPct = $this->loadPct($plan);

            if (isset($rows[$dimension]) && $rows[$dimension]['worst_load_pct'] >= $loadPct) {
                continue;
            }

            $rows[$dimension] = [
                'dimension' => $dimension,
                'status' => $this->isOverloaded($plan) ? 'over' : 'ok',
                'worst_label' => $plan->resourceGroup?->name ?? "resource #{$plan->resource_ref_id}",
                'worst_load_pct' => $loadPct,
            ];
        }

        return $rows;
    }

    /** @return array{dimension: string, unit: string} */
    private function resourceDimensionInfo(Resource $resource): array
    {
        $dimension = match ($resource->type) {
            Resource::TYPE_TANK => 'tank',
            Resource::TYPE_UTILITY => 'utility',
            Resource::TYPE_WAREHOUSE => 'storage',
            Resource::TYPE_TOOL => 'tool',
            default => 'unclassified',
        };

        return ['dimension' => $dimension, 'unit' => $resource->uom_code ?? 'hr'];
    }

    /** A mixed-type or empty resource group has no single dimension — surfaced as `unclassified` rather than guessed. */
    private function groupDimensionInfo(int $groupId): array
    {
        $members = ResourceGroupMember::query()->where('resource_group_id', $groupId)->get();
        if ($members->isEmpty()) {
            return ['dimension' => 'unclassified', 'unit' => 'hr'];
        }

        $infos = $members->map(function (ResourceGroupMember $member) {
            if ($member->resource_type === ResourceGroupMember::TYPE_PP_RESOURCE) {
                $resource = Resource::query()->find($member->resource_ref_id);

                return $resource ? $this->resourceDimensionInfo($resource) : ['dimension' => 'unclassified', 'unit' => 'hr'];
            }

            return ['dimension' => 'machine', 'unit' => 'hr'];
        });

        return $infos->pluck('dimension')->unique()->count() === 1
            ? $infos->first()
            : ['dimension' => 'unclassified', 'unit' => 'hr'];
    }

    /** PP_SPECS.md §3F Rules/Logic — "writes a §3M exception" on overload. Scenario rows (§3N) are excluded: pp_exceptions carries no scenario_id, so a what-if plan must never write into the real read model. */
    private function checkOverload(CapacityPlan $plan): void
    {
        if ($plan->scenario_id !== null || ! $this->isOverloaded($plan)) {
            return;
        }

        $loadPct = $this->loadPct($plan);
        $severity = match (true) {
            $loadPct >= 150 => PpException::SEVERITY_CRITICAL,
            $loadPct >= 120 => PpException::SEVERITY_HIGH,
            default => PpException::SEVERITY_MEDIUM,
        };

        $target = $plan->resource_group_id
            ? ($plan->resourceGroup?->name ?? "resource group #{$plan->resource_group_id}")
            : strtoupper(str_replace('_', ' ', (string) $plan->resource_type))." #{$plan->resource_ref_id}";

        $this->exceptions->record(
            PpException::TYPE_CAPACITY_OVERLOAD,
            PpException::SUBJECT_CAPACITY_PLAN,
            $plan->id,
            "{$target} loaded at {$loadPct}% ({$plan->required_hours}hr required vs {$plan->available_hours}hr available) for {$plan->period_start->toDateString()}–{$plan->period_end->toDateString()}.",
            $severity,
        );
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
