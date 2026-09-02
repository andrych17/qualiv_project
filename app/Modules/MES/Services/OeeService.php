<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\BatchParameterReading;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\QcResult;
use App\Modules\MES\Models\RoutingOp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * MES_SPECS.md §3O — OEE & Process KPIs, a pure read model over §3C/§3J/§3L/§3M (no KPI storage
 * table, per the spec's own words). Scoped to a single calendar Day, and to a Work Center rather
 * than the spec mockup's Machine — `OperationExecutionService::start()`/`complete()` never
 * record which physical machine ran an assembly operation (only `RoutingOp::work_center_id`
 * exists on the op itself), so Machine is not a joinable dimension for Performance/Quality in
 * this build. Work Center is the finest grain the data actually supports; Line (§3D's
 * `area_line`) and Day remain available filters on top of it.
 */
class OeeService
{
    /** @return array<string, mixed> */
    public function summary(?int $workCenterId, string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $dayEnd = (clone $day)->endOfDay();

        return [
            'date' => $day->toDateString(),
            'work_center_id' => $workCenterId,
            'assembly' => $this->assembly($workCenterId, $day, $dayEnd),
            'qc_pass_rate_pct' => $this->qcPassRatePct($day, $dayEnd),
            'process' => $this->process($day, $dayEnd),
        ];
    }

    /** @return array<string, mixed> */
    private function assembly(?int $workCenterId, Carbon $day, Carbon $dayEnd): array
    {
        $opIds = RoutingOp::query()
            ->when($workCenterId, fn ($q) => $q->where('work_center_id', $workCenterId))
            ->pluck('id');

        if ($opIds->isEmpty()) {
            return $this->emptyAssembly();
        }

        $opsById = RoutingOp::query()->whereIn('id', $opIds)->get()->keyBy('id');

        [$operatingMinutes, $standardMinutes] = $this->operationSpans($opIds, $opsById, $day, $dayEnd);
        $downtimeMinutes = $this->downtimeMinutes($workCenterId, $day, $dayEnd);
        $quality = $this->outputQualityPct($opIds, $day, $dayEnd);

        $totalMinutes = $operatingMinutes + $downtimeMinutes;
        $availability = $totalMinutes > 0 ? round(($operatingMinutes / $totalMinutes) * 100, 1) : null;
        $performance = $operatingMinutes > 0 ? round(($standardMinutes / $operatingMinutes) * 100, 1) : null;

        $oee = ($availability !== null && $performance !== null && $quality !== null)
            ? round(($availability / 100) * ($performance / 100) * ($quality / 100) * 100, 1)
            : null;

        return [
            'availability_pct' => $availability,
            'performance_pct' => $performance,
            'quality_pct' => $quality,
            'oee_pct' => $oee,
            'operating_minutes' => round($operatingMinutes, 1),
            'downtime_minutes' => round($downtimeMinutes, 1),
        ];
    }

    /**
     * Pairs each `operation_completed` event landing in the day with the immediately preceding
     * `operation_started` for the same `(order_id, operation_ref)` — `OperationExecutionService`
     * writes no ledger event for `resume()` (its own docblock says so), so a paused-then-resumed
     * op still only has started/paused/completed rows. The pause gap is therefore counted as
     * Operating Time; there is no ledger event to bound it out. One query, grouped in memory,
     * rather than one started-lookup query per completed event.
     *
     * @param  Collection<int, int>  $opIds
     * @param  Collection<int, RoutingOp>  $opsById
     * @return array{0: float, 1: float} [operatingMinutes, standardMinutes]
     */
    private function operationSpans(Collection $opIds, Collection $opsById, Carbon $day, Carbon $dayEnd): array
    {
        $events = ProdEvent::query()
            ->whereIn('operation_ref', $opIds)
            ->whereIn('event_type', [ProdEvent::TYPE_OPERATION_STARTED, ProdEvent::TYPE_OPERATION_COMPLETED])
            // Bounded lookback so a completion just after midnight still finds its start; a
            // single operation running longer than this isn't expected on a shop floor.
            ->where('occurred_at', '>=', $day->copy()->subDays(2))
            ->where('occurred_at', '<=', $dayEnd)
            ->orderBy('occurred_at')
            ->get(['order_id', 'operation_ref', 'event_type', 'occurred_at', 'payload']);

        $operatingMinutes = 0.0;
        $standardMinutes = 0.0;

        foreach ($events->groupBy(fn (ProdEvent $e) => "{$e->order_id}:{$e->operation_ref}") as $pair) {
            $lastStart = null;

            foreach ($pair as $event) {
                if ($event->event_type === ProdEvent::TYPE_OPERATION_STARTED) {
                    $lastStart = $event;

                    continue;
                }

                if ($lastStart === null) {
                    continue; // completion with no start in the lookback window — skip, not this day's to count
                }

                if ($event->occurred_at->between($day, $dayEnd)) {
                    $operatingMinutes += $lastStart->occurred_at->diffInMinutes($event->occurred_at);

                    $op = $opsById->get($event->operation_ref);
                    if ($op) {
                        $qtyCompleted = (float) ($event->payload['qty_completed'] ?? 0);
                        $standardMinutes += $this->standardMinutesFor($op, $qtyCompleted);
                    }
                }

                $lastStart = null;
            }
        }

        return [$operatingMinutes, $standardMinutes];
    }

    private function standardMinutesFor(RoutingOp $op, float $qtyCompleted): float
    {
        $standardQty = (float) ($op->standard_output_qty ?? 0);

        if ($standardQty > 0) {
            return (float) $op->run_time_minutes * ($qtyCompleted / $standardQty);
        }

        return (float) $op->run_time_minutes;
    }

    /**
     * Both planned and unplanned downtime widen the denominator this build's Availability is
     * measured against (§3M note in DowntimeService — Operating + Downtime stands in for
     * "Planned Production Time" since no shift calendar exists yet, §3P not built).
     */
    private function downtimeMinutes(?int $workCenterId, Carbon $day, Carbon $dayEnd): float
    {
        $machineIds = $workCenterId !== null ? Machine::query()->where('work_center_id', $workCenterId)->pluck('id') : null;

        return (float) DowntimeEvent::query()
            ->when($machineIds !== null, fn ($q) => $q->where(function ($q2) use ($machineIds, $workCenterId) {
                $q2->whereIn('machine_id', $machineIds)->orWhere('work_center_id', $workCenterId);
            }))
            ->where('started_at', '<=', $dayEnd)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $day))
            ->get(['started_at', 'ended_at'])
            ->sum(fn (DowntimeEvent $d) => $this->overlapMinutes($d->started_at, $d->ended_at ?? now(), $day, $dayEnd));
    }

    private function overlapMinutes(Carbon $start, Carbon $end, Carbon $rangeStart, Carbon $rangeEnd): float
    {
        $clampedStart = $start->lt($rangeStart) ? $rangeStart : $start;
        $clampedEnd = $end->gt($rangeEnd) ? $rangeEnd : $end;

        return $clampedEnd->gt($clampedStart) ? $clampedStart->diffInMinutes($clampedEnd) : 0.0;
    }

    /**
     * Uses §3N's output-based good/(good+scrap) — via `ProductionOutput.operation_ref` →
     * `RoutingOp.work_center_id`, a real per-work-center join — rather than §3L QC results,
     * which attach to an order or batch phase, not an operation/work-center. Attributing one
     * sample's pass/fail to every work center on its order's routing would overstate Quality at
     * work centers the sample never touched; `qcPassRatePct()` below surfaces the §3L signal
     * separately instead, at the only grain it actually supports (order-scoped, this day).
     *
     * @param  Collection<int, int>  $opIds
     */
    private function outputQualityPct(Collection $opIds, Carbon $day, Carbon $dayEnd): ?float
    {
        $base = ProductionOutput::query()->whereIn('operation_ref', $opIds)->whereBetween('created_at', [$day, $dayEnd]);

        $good = (float) (clone $base)->where('output_type', '!=', ProductionOutput::TYPE_WASTE)->sum('qty');
        $scrap = (float) (clone $base)->where('output_type', ProductionOutput::TYPE_WASTE)->sum('qty');
        $denominator = $good + $scrap;

        return $denominator > 0 ? round(($good / $denominator) * 100, 1) : null;
    }

    private function qcPassRatePct(Carbon $day, Carbon $dayEnd): ?float
    {
        $results = QcResult::query()
            ->whereHas('sample', fn ($q) => $q->whereBetween('taken_at', [$day, $dayEnd]))
            ->get(['result']);

        if ($results->isEmpty()) {
            return null;
        }

        return round(($results->where('result', QcResult::RESULT_PASS)->count() / $results->count()) * 100, 1);
    }

    /** @return array<string, mixed> */
    private function process(Carbon $day, Carbon $dayEnd): array
    {
        return [
            'yield_pct' => $this->processYieldPct($day, $dayEnd),
            'parameter_in_spec_pct' => $this->parameterInSpecPct($day, $dayEnd),
            'qc_hold_count' => QcHold::query()->open()->count(),
        ];
    }

    private function processYieldPct(Carbon $day, Carbon $dayEnd): ?float
    {
        $base = ProductionOutput::query()
            ->whereHas('order', fn ($q) => $q->where('production_model', ProdOrder::MODEL_PROCESS))
            ->whereBetween('created_at', [$day, $dayEnd]);

        $good = (float) (clone $base)->where('output_type', '!=', ProductionOutput::TYPE_WASTE)->sum('qty');
        $scrap = (float) (clone $base)->where('output_type', ProductionOutput::TYPE_WASTE)->sum('qty');
        $denominator = $good + $scrap;

        return $denominator > 0 ? round(($good / $denominator) * 100, 1) : null;
    }

    private function parameterInSpecPct(Carbon $day, Carbon $dayEnd): ?float
    {
        $readings = BatchParameterReading::query()
            ->with('parameter:id,min_value,max_value')
            ->whereBetween('recorded_at', [$day, $dayEnd])
            ->get(['process_parameter_id', 'value']);

        if ($readings->isEmpty()) {
            return null;
        }

        $inSpec = $readings->filter(function (BatchParameterReading $reading) {
            $parameter = $reading->parameter;
            if ($parameter === null) {
                return false;
            }

            $value = (float) $reading->value;
            if ($parameter->min_value !== null && $value < (float) $parameter->min_value) {
                return false;
            }
            if ($parameter->max_value !== null && $value > (float) $parameter->max_value) {
                return false;
            }

            return true;
        })->count();

        return round(($inSpec / $readings->count()) * 100, 1);
    }

    /** @return array<string, mixed> */
    private function emptyAssembly(): array
    {
        return [
            'availability_pct' => null,
            'performance_pct' => null,
            'quality_pct' => null,
            'oee_pct' => null,
            'operating_minutes' => 0.0,
            'downtime_minutes' => 0.0,
        ];
    }
}
