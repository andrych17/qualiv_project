<?php

namespace App\Modules\PP\Services;

use App\Modules\PP\Models\CapacityPlan;
use App\Modules\PP\Models\DemandHeader;
use App\Modules\PP\Models\DemandLine;
use App\Modules\PP\Models\PlannedOrder;
use App\Modules\PP\Models\PpException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PP_SPECS.md §3O — the Production Planning Dashboard's read model. "No dashboard-only storage"
 * (the spec's own words): every figure here is a live aggregate over §3B (demand)/§3D (planned
 * orders)/§3F (capacity, via `CapacityPlanService`)/§3M (exceptions) — nothing is written here.
 * Scoped to the current calendar month; the spec's own mockup shows a single named month
 * ("September 2026") rather than a period picker, so that's the MVP shape.
 */
class DashboardService
{
    public function __construct(protected CapacityPlanService $capacity) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        $demandQty = $this->demandQty($periodStart, $periodEnd);
        $plannedQty = $this->plannedQty($periodStart, $periodEnd);
        $capacityBars = $this->capacityBars($periodStart, $periodEnd);
        $openOrders = PlannedOrder::query()->baseline()
            ->whereIn('status', [PlannedOrder::STATUS_PLANNED, PlannedOrder::STATUS_FIRMED]);

        return [
            'period_label' => $periodStart->format('F Y'),
            'demand_qty' => $demandQty,
            'planned_qty' => $plannedQty,
            'gap_qty' => $demandQty - $plannedQty,
            'capacity_pct' => $this->average($capacityBars->pluck('load_pct')),
            'material_pct' => $this->materialAvailabilityPct($periodStart, $periodEnd),
            'on_time_pct' => $this->onTimePct($openOrders),
            'capacity_bars' => $capacityBars->values()->all(),
            'exception_counts' => $this->exceptionCounts(),
            'orders_ready_count' => $this->ordersReadyCount($openOrders),
        ];
    }

    private function demandQty(Carbon $start, Carbon $end): float
    {
        return (float) DemandLine::query()->baseline()
            ->whereHas('header', fn ($q) => $q->where('source_type', '!=', DemandHeader::SOURCE_DEPENDENT))
            ->whereBetween('need_by_date', [$start->toDateString(), $end->toDateString()])
            ->sum('qty');
    }

    private function plannedQty(Carbon $start, Carbon $end): float
    {
        return (float) PlannedOrder::query()->baseline()
            ->where('order_type', PlannedOrder::TYPE_PRODUCTION)
            ->where('status', '!=', PlannedOrder::STATUS_CANCELLED)
            ->whereBetween('need_by_date', [$start->toDateString(), $end->toDateString()])
            ->sum('qty');
    }

    /**
     * @return Collection<int, array{label: string, load_pct: float, overloaded: bool}>
     */
    private function capacityBars(Carbon $start, Carbon $end): Collection
    {
        return CapacityPlan::query()->baseline()
            ->where('period_start', '<=', $end->toDateString())
            ->where('period_end', '>=', $start->toDateString())
            ->with('resourceGroup:id,code,name')
            ->get()
            // Plans key to a resource group OR a specific resource, never both
            // (CapacityPlanService::attributes()) — group by whichever this row has.
            ->groupBy(fn (CapacityPlan $p) => $p->resource_group_id ?: "{$p->resource_type}:{$p->resource_ref_id}")
            ->map(function ($plans) {
                // Worst-case per dimension, same "one bar per dimension, no meaningful sum across
                // rows" reasoning as CapacityPlanService::dimensionRollup().
                $worst = $plans->sortByDesc(fn (CapacityPlan $p) => $this->capacity->loadPct($p))->first();

                $label = $worst->resource_group_id
                    ? ($worst->resourceGroup?->name ?? $worst->resourceGroup?->code ?? "Group #{$worst->resource_group_id}")
                    : strtoupper(str_replace('_', ' ', (string) $worst->resource_type))." #{$worst->resource_ref_id}";

                return [
                    'label' => $label,
                    'load_pct' => $this->capacity->loadPct($worst),
                    'overloaded' => $this->capacity->isOverloaded($worst),
                ];
            })
            ->sortByDesc('load_pct')
            ->values();
    }

    /**
     * Distinct products touched by this period's demand or planned production, minus those with
     * an open/acknowledged §3L material-shortage exception on one of their planned orders.
     */
    private function materialAvailabilityPct(Carbon $start, Carbon $end): ?float
    {
        $demandProductIds = DemandLine::query()->baseline()
            ->whereBetween('need_by_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('product_id');
        $plannedProductIds = PlannedOrder::query()->baseline()
            ->whereBetween('need_by_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('product_id');
        $productIds = $demandProductIds->merge($plannedProductIds)->unique();

        if ($productIds->isEmpty()) {
            return null;
        }

        $shortageOrderIds = PpException::query()
            ->where('exception_type', PpException::TYPE_MATERIAL_SHORTAGE)
            ->where('status', '!=', PpException::STATUS_RESOLVED)
            ->where('subject_type', PpException::SUBJECT_PLANNED_ORDER)
            ->pluck('subject_id');
        $shortagedProductIds = PlannedOrder::query()->whereIn('id', $shortageOrderIds)->pluck('product_id')->unique();

        $shortagedCount = $productIds->intersect($shortagedProductIds)->count();

        return round((($productIds->count() - $shortagedCount) / $productIds->count()) * 100, 1);
    }

    /** @param  Builder<PlannedOrder>  $openOrders */
    private function onTimePct(Builder $openOrders): ?float
    {
        $totalOpen = (clone $openOrders)->count();
        if ($totalOpen === 0) {
            return null;
        }

        $lateOpenCount = (clone $openOrders)->whereIn('id', $this->lateOrderIds())->count();

        return round((($totalOpen - $lateOpenCount) / $totalOpen) * 100, 1);
    }

    /** @param  Builder<PlannedOrder>  $openOrders */
    private function ordersReadyCount(Builder $openOrders): int
    {
        $exceptedOrderIds = PpException::query()
            ->where('status', '!=', PpException::STATUS_RESOLVED)
            ->where('subject_type', PpException::SUBJECT_PLANNED_ORDER)
            ->pluck('subject_id')
            ->unique();

        return (clone $openOrders)->whereNotIn('id', $exceptedOrderIds)->count();
    }

    /** @return Collection<int, int> */
    private function lateOrderIds(): Collection
    {
        return PpException::query()
            ->whereIn('exception_type', [PpException::TYPE_LATE_ORDER, PpException::TYPE_LATE_PURCHASE])
            ->where('status', '!=', PpException::STATUS_RESOLVED)
            ->where('subject_type', PpException::SUBJECT_PLANNED_ORDER)
            ->pluck('subject_id');
    }

    /** @return array<string, int> */
    private function exceptionCounts(): array
    {
        return PpException::query()
            ->where('status', '!=', PpException::STATUS_RESOLVED)
            ->selectRaw('exception_type, count(*) as total')
            ->groupBy('exception_type')
            ->pluck('total', 'exception_type')
            ->all();
    }

    /** @param  Collection<int, float>  $values */
    private function average(Collection $values): ?float
    {
        return $values->isEmpty() ? null : round((float) $values->avg(), 1);
    }
}
