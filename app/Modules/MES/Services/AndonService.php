<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\AndonAlert;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\SysConfig\Services\ConfigService;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Support\Collection;

/**
 * MES_SPECS.md §3R — Alerts & Andon (Phase 3, built now per explicit override — see the
 * `mes_andon_alerts` migration's own docblock).
 *
 * `board()` is a pure read model, per the spec's own words — no Andon *state* is stored, it's
 * derived fresh from `mes_machines.status` on every call. `checkAndFireAlerts()` is the one
 * place that writes anything (`mes_andon_alerts`), and only for delivery-idempotency bookkeeping,
 * not the board itself. Run periodically (see `mes:check-andon-alerts`), same sweep posture
 * `DowntimeService::checkOpenThresholds()` already uses.
 */
class AndonService
{
    public function __construct(
        protected DispatchQueueService $dispatchQueue,
        protected ConfigService $config,
    ) {}

    /** @return list<array<string, mixed>> */
    public function board(?int $workCenterId): array
    {
        $machines = Machine::query()
            ->with('workCenter:id,code,name')
            ->when($workCenterId, fn ($q) => $q->where('work_center_id', $workCenterId))
            ->orderBy('code')
            ->get();

        $attentionMachineIds = $this->machineIdsWithOpenParameterAlert();

        return $machines->map(function (Machine $machine) use ($attentionMachineIds) {
            return [
                'machine_id' => $machine->id,
                'code' => $machine->code,
                'name' => $machine->name,
                'work_center_id' => $machine->work_center_id,
                'work_center_code' => $machine->workCenter?->code,
                'status' => $machine->status,
                'andon_state' => $this->andonStateFor($machine, $attentionMachineIds),
            ];
        })->values()->all();
    }

    /**
     * Detects the six alert conditions fresh, opens a new `mes_andon_alerts` row (and fires
     * `NotificationRequested`) for any condition newly true, and auto-resolves any open alert
     * whose condition is no longer true. Idempotent: a condition that's still true on the next
     * sweep is not re-notified (`idx_mes_andon_alerts_open` + the pre-check below).
     *
     * @return int alerts newly fired this sweep
     */
    public function checkAndFireAlerts(): int
    {
        $maintenanceRole = (string) ($this->config->get('MES', 'MAINTENANCE_CONTACT_ROLE') ?? 'ADMIN');
        $supervisorRole = (string) ($this->config->get('MES', 'ANDON_ALERT_ROLE') ?? 'ADMIN');

        $fired = 0;
        $fired += $this->sync(AndonAlert::TYPE_MACHINE_STOPPED, 'mes.mes_machines', $this->machineStoppedConditions(), $maintenanceRole);
        $fired += $this->sync(AndonAlert::TYPE_MAINTENANCE_REQUIRED, 'mes.mes_machines', $this->maintenanceRequiredConditions(), $maintenanceRole);
        $fired += $this->sync(AndonAlert::TYPE_MATERIAL_SHORTAGE, 'mes.mes_prod_order_hdrs', $this->materialShortageConditions(), $supervisorRole);
        $fired += $this->sync(AndonAlert::TYPE_OUT_OF_SPEC_PARAMETER, 'mes.mes_batch_phases', $this->outOfSpecParameterConditions(), $supervisorRole);
        $fired += $this->sync(AndonAlert::TYPE_BEHIND_SCHEDULE, 'mes.mes_prod_order_hdrs', $this->behindScheduleConditions(), $supervisorRole);
        $fired += $this->sync(AndonAlert::TYPE_OVERDUE_BATCH, 'mes.mes_batches', $this->overdueBatchConditions(), $supervisorRole);

        return $fired;
    }

    private function andonStateFor(Machine $machine, Collection $attentionMachineIds): string
    {
        if ($machine->status === Machine::STATUS_DOWN) {
            return 'stopped';
        }
        if ($machine->status === Machine::STATUS_MAINTENANCE) {
            return 'maintenance';
        }
        if (in_array($machine->status, [Machine::STATUS_WAITING_MATERIAL, Machine::STATUS_WAITING_OPERATOR, Machine::STATUS_WAITING_QC, Machine::STATUS_SETUP], true)) {
            return 'attention';
        }
        if ($attentionMachineIds->contains($machine->id)) {
            return 'attention';
        }

        return 'running';
    }

    /** @return Collection<int, int> distinct machine_ids currently running a batch phase with an open out-of-spec-parameter alert */
    private function machineIdsWithOpenParameterAlert(): Collection
    {
        $phaseIds = AndonAlert::query()->open()->where('alert_type', AndonAlert::TYPE_OUT_OF_SPEC_PARAMETER)->pluck('subject_id');

        return BatchPhase::query()->whereIn('id', $phaseIds)->whereNotNull('machine_id')->pluck('machine_id')->unique()->values();
    }

    /** @return array<int, array{severity: string, message: string, payload: array<string, mixed>}> */
    private function machineStoppedConditions(): array
    {
        return Machine::query()->where('status', Machine::STATUS_DOWN)->get()
            ->mapWithKeys(fn (Machine $m) => [$m->id => [
                'severity' => 'critical',
                'message' => "Machine {$m->code} ({$m->name}) is stopped.",
                'payload' => ['machine_id' => $m->id, 'machine_code' => $m->code],
            ]])->all();
    }

    /** @return array<int, array{severity: string, message: string, payload: array<string, mixed>}> */
    private function maintenanceRequiredConditions(): array
    {
        return Machine::query()->where('status', Machine::STATUS_MAINTENANCE)->get()
            ->mapWithKeys(fn (Machine $m) => [$m->id => [
                'severity' => 'warning',
                'message' => "Machine {$m->code} ({$m->name}) is under maintenance.",
                'payload' => ['machine_id' => $m->id, 'machine_code' => $m->code],
            ]])->all();
    }

    /** @return array<int, array{severity: string, message: string, payload: array<string, mixed>}> */
    private function materialShortageConditions(): array
    {
        return ProdOrder::query()
            ->whereIn('status', [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED])
            ->get()
            ->filter(fn (ProdOrder $order) => $this->dispatchQueue->materialStatus($order) === 'shortage')
            ->mapWithKeys(fn (ProdOrder $order) => [$order->id => [
                'severity' => 'warning',
                'message' => "Order {$order->order_number} has a material shortage.",
                'payload' => ['order_id' => $order->id, 'order_number' => $order->order_number],
            ]])->all();
    }

    /** @return array<int, array{severity: string, message: string, payload: array<string, mixed>}> */
    private function outOfSpecParameterConditions(): array
    {
        return BatchPhase::query()
            ->whereIn('status', [BatchPhase::STATUS_RUNNING, BatchPhase::STATUS_PAUSED])
            ->with(['readings.parameter', 'processPhase:id,phase_name'])
            ->get()
            ->mapWithKeys(function (BatchPhase $phase) {
                $latest = $phase->readings->sortByDesc('recorded_at')->first();
                if ($latest === null || $latest->parameter === null) {
                    return [];
                }

                $value = (float) $latest->value;
                $parameter = $latest->parameter;
                $outOfRange = ($parameter->min_value !== null && $value < (float) $parameter->min_value)
                    || ($parameter->max_value !== null && $value > (float) $parameter->max_value);

                if (! $outOfRange) {
                    return [];
                }

                return [$phase->id => [
                    'severity' => 'critical',
                    'message' => "Phase {$phase->processPhase?->phase_name} — parameter {$parameter->parameter_code} out of spec ({$value}).",
                    'payload' => ['batch_phase_id' => $phase->id, 'batch_id' => $phase->batch_id, 'parameter_code' => $parameter->parameter_code, 'value' => $value],
                ]];
            })->all();
    }

    /** @return array<int, array{severity: string, message: string, payload: array<string, mixed>}> */
    private function behindScheduleConditions(): array
    {
        return ProdOrder::query()
            ->where('production_model', ProdOrder::MODEL_ASSEMBLY)
            ->whereIn('status', [ProdOrder::STATUS_RELEASED, ProdOrder::STATUS_IN_PROGRESS, ProdOrder::STATUS_PAUSED])
            ->whereNotNull('planned_end')
            ->where('planned_end', '<', now())
            ->get()
            ->mapWithKeys(fn (ProdOrder $order) => [$order->id => [
                'severity' => 'warning',
                'message' => "Order {$order->order_number} is behind schedule (due {$order->planned_end->toDateTimeString()}).",
                'payload' => ['order_id' => $order->id, 'order_number' => $order->order_number, 'planned_end' => $order->planned_end->toIso8601String()],
            ]])->all();
    }

    /** @return array<int, array{severity: string, message: string, payload: array<string, mixed>}> */
    private function overdueBatchConditions(): array
    {
        return MesBatch::query()
            ->whereIn('status', [MesBatch::STATUS_RUNNING, MesBatch::STATUS_PAUSED])
            ->whereHas('order', fn ($q) => $q->whereNotNull('planned_end')->where('planned_end', '<', now()))
            ->with('order:id,order_number,planned_end')
            ->get()
            ->mapWithKeys(fn (MesBatch $batch) => [$batch->id => [
                'severity' => 'warning',
                'message' => "Batch {$batch->batch_number} is overdue (order {$batch->order?->order_number} due {$batch->order?->planned_end?->toDateTimeString()}).",
                'payload' => ['batch_id' => $batch->id, 'batch_number' => $batch->batch_number, 'order_id' => $batch->order_id],
            ]])->all();
    }

    /**
     * @param  array<int, array{severity: string, message: string, payload: array<string, mixed>}>  $current
     */
    private function sync(string $alertType, string $subjectType, array $current, string $role): int
    {
        $openAlerts = AndonAlert::query()->open()->where('alert_type', $alertType)->where('subject_type', $subjectType)->get()->keyBy('subject_id');

        $fired = 0;
        foreach ($current as $subjectId => $info) {
            if ($openAlerts->has($subjectId)) {
                continue;
            }

            $alert = AndonAlert::query()->create([
                'alert_type' => $alertType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'severity' => $info['severity'],
                'message' => $info['message'],
                'fired_at' => now(),
            ]);

            NotificationRequested::dispatch(
                category: "mes.andon_{$alertType}",
                recipient: ['type' => 'role', 'role' => $role],
                payload: array_merge(['andon_alert_id' => $alert->id], $info['payload']),
                subjectType: $subjectType,
                subjectId: $subjectId,
                subject: $info['message'],
                body: $info['message'],
            );

            $fired++;
        }

        foreach ($openAlerts as $subjectId => $alert) {
            if (! array_key_exists($subjectId, $current)) {
                $alert->update(['resolved_at' => now()]);
            }
        }

        return $fired;
    }
}
