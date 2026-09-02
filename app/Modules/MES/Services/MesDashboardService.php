<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\AndonAlert;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\ProductionOutput;
use App\Modules\MES\Models\QcHold;
use App\Modules\MES\Models\RoutingOp;
use App\Modules\MES\Models\WorkCenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * MES_SPECS.md §3T — Dashboards. Three focused read models over §3C/§3J/§3L/§3M/§3O, per the
 * spec's own "several focused dashboards, not one giant one." No KPI storage — everything here
 * is computed on read, same posture as `OeeService`.
 *
 * Plant and Process Area are plant-/area-wide aggregate KPI panels; Line is the one dashboard
 * the spec itself calls "per-line," so it returns one row per discrete `WorkCenter` — mirroring
 * how discrete manufacturing is organized into distinct lines while process manufacturing is
 * organized into one area, not per-work-center lines.
 */
class MesDashboardService
{
    public function __construct(protected OeeService $oee) {}

    /** @return array<string, mixed> */
    public function plant(string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $dayEnd = (clone $day)->endOfDay();

        $oee = $this->oee->summary(null, $date);
        $dueToday = ProdOrder::query()->whereBetween('planned_end', [$day, $dayEnd])->get(['id', 'status']);

        return [
            'date' => $day->toDateString(),
            'production_to_plan_pct' => $dueToday->isNotEmpty()
                ? round(($dueToday->where('status', ProdOrder::STATUS_COMPLETED)->count() / $dueToday->count()) * 100, 1)
                : null,
            'oee_pct' => $oee['assembly']['oee_pct'],
            'process_yield_pct' => $oee['process']['yield_pct'],
            'downtime_minutes' => $this->downtimeMinutes(null, $day, $dayEnd),
            'reject_rate_pct' => $this->rejectRatePct(null, $day, $dayEnd),
            'active_orders' => ProdOrder::query()->whereIn('status', [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED])->count(),
            'active_batches' => MesBatch::query()->whereIn('status', [MesBatch::STATUS_RUNNING, MesBatch::STATUS_PAUSED])->count(),
            'open_andon_alert_count' => AndonAlert::query()->open()->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function lines(string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $dayEnd = (clone $day)->endOfDay();

        return WorkCenter::query()->where('type', WorkCenter::TYPE_DISCRETE)->orderBy('code')->get()
            ->map(function (WorkCenter $wc) use ($date, $day, $dayEnd) {
                $machines = Machine::query()->where('work_center_id', $wc->id)->get(['status']);
                $oee = $this->oee->summary($wc->id, $date);

                $opIds = RoutingOp::query()->where('work_center_id', $wc->id)->pluck('id');
                $targetQty = ProdOrder::query()
                    ->whereBetween('planned_end', [$day, $dayEnd])
                    ->whereHas('routing.ops', fn ($q) => $q->where('work_center_id', $wc->id))
                    ->sum('qty');
                $actualQty = (float) ProductionOutput::query()
                    ->whereIn('operation_ref', $opIds)
                    ->whereBetween('created_at', [$day, $dayEnd])
                    ->where('output_type', '!=', ProductionOutput::TYPE_WASTE)
                    ->sum('qty');
                $rejectQty = (float) ProductionOutput::query()
                    ->whereIn('operation_ref', $opIds)
                    ->whereBetween('created_at', [$day, $dayEnd])
                    ->where('output_type', ProductionOutput::TYPE_WASTE)
                    ->sum('qty');

                return [
                    'work_center_id' => $wc->id,
                    'code' => $wc->code,
                    'name' => $wc->name,
                    'area_line' => $wc->area_line,
                    'running_state' => $this->lineRunningState($machines),
                    'oee_pct' => $oee['assembly']['oee_pct'],
                    'target_qty' => (float) $targetQty,
                    'actual_qty' => $actualQty,
                    'reject_qty' => $rejectQty,
                    'downtime_minutes' => $oee['assembly']['downtime_minutes'],
                ];
            })->all();
    }

    /** @return array<string, mixed> */
    public function processArea(string $date): array
    {
        $day = Carbon::parse($date)->startOfDay();
        $dayEnd = (clone $day)->endOfDay();

        $processWorkCenterIds = WorkCenter::query()->where('type', WorkCenter::TYPE_PROCESS)->pluck('id');
        $phaseIds = BatchPhase::query()->whereHas('processPhase', fn ($q) => $q->whereIn('work_center_id', $processWorkCenterIds))->pluck('id');

        $activeBatches = BatchPhase::query()
            ->whereIn('id', $phaseIds)
            ->whereIn('status', [BatchPhase::STATUS_RUNNING, BatchPhase::STATUS_PAUSED])
            ->distinct('batch_id')->count('batch_id');

        $completedToday = MesBatch::query()->where('status', MesBatch::STATUS_COMPLETED)->whereBetween('updated_at', [$day, $dayEnd])->get(['actual_yield_pct']);

        return [
            'date' => $day->toDateString(),
            'active_batches' => $activeBatches,
            'average_yield_pct' => $completedToday->isNotEmpty() ? round((float) $completedToday->avg('actual_yield_pct'), 1) : null,
            'parameter_alarm_count' => AndonAlert::query()->open()->where('alert_type', AndonAlert::TYPE_OUT_OF_SPEC_PARAMETER)->whereIn('subject_id', $phaseIds)->count(),
            'qc_hold_count' => QcHold::query()->open()->count(),
        ];
    }

    private function lineRunningState(Collection $machines): string
    {
        if ($machines->isEmpty()) {
            return 'idle';
        }
        if ($machines->contains('status', Machine::STATUS_DOWN)) {
            return 'stopped';
        }
        if ($machines->contains('status', Machine::STATUS_MAINTENANCE)) {
            return 'maintenance';
        }
        if ($machines->contains('status', Machine::STATUS_RUNNING)) {
            return 'running';
        }

        return 'idle';
    }

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
            ->sum(function (DowntimeEvent $d) use ($day, $dayEnd) {
                $start = $d->started_at->lt($day) ? $day : $d->started_at;
                $end = ($d->ended_at ?? now())->gt($dayEnd) ? $dayEnd : ($d->ended_at ?? now());

                return $end->gt($start) ? $start->diffInMinutes($end) : 0.0;
            });
    }

    private function rejectRatePct(?int $workCenterId, Carbon $day, Carbon $dayEnd): ?float
    {
        $base = ProductionOutput::query()->whereBetween('created_at', [$day, $dayEnd]);

        $good = (float) (clone $base)->where('output_type', '!=', ProductionOutput::TYPE_WASTE)->sum('qty');
        $scrap = (float) (clone $base)->where('output_type', ProductionOutput::TYPE_WASTE)->sum('qty');
        $denominator = $good + $scrap;

        return $denominator > 0 ? round(($scrap / $denominator) * 100, 1) : null;
    }
}
