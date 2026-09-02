<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\EquipmentStatusLog;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\SysConfig\Services\ConfigService;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3M — Equipment Status & Downtime. Owns the one write path for
 * `mes_downtime_events` and `mes_equipment_status_logs`, and denormalizes the resulting status
 * onto `mes_machines.status` (§3D), same "one write path" discipline `ProdEventService` already
 * enforces for the ledger itself.
 *
 * The production event ledger's `order_id` is NOT NULL (§3C migration), so a downtime span with
 * no order — logged against equipment sitting idle between orders — cannot carry a
 * `downtime_started`/`downtime_ended` ledger row. Those rows are only written when the downtime
 * event itself names an order; orderless downtime lives in `mes_downtime_events` alone, which is
 * still the system of record §3O's OEE reads from.
 */
class DowntimeService
{
    public function __construct(
        protected ProdEventService $events,
        protected ConfigService $config,
    ) {}

    /**
     * @param  array{machine_id?: int|null, work_center_id?: int|null, order_id?: int|null, category: string, reason_code: string}  $data
     */
    public function start(array $data, int $userId): DowntimeEvent
    {
        $machineId = $data['machine_id'] ?? null;
        $workCenterId = $data['work_center_id'] ?? null;

        return DB::transaction(function () use ($data, $machineId, $workCenterId, $userId) {
            $openExists = DowntimeEvent::query()->open()
                ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
                ->when(! $machineId && $workCenterId, fn ($q) => $q->where('work_center_id', $workCenterId))
                ->exists();

            if ($openExists) {
                throw ValidationException::withMessages(['machine_id' => 'This equipment already has an open downtime event.']);
            }

            $downtime = DowntimeEvent::query()->create([
                'machine_id' => $machineId,
                'work_center_id' => $workCenterId,
                'order_id' => $data['order_id'] ?? null,
                'category' => $data['category'],
                'reason_code' => $data['reason_code'],
                'started_at' => now(),
                'started_by' => $userId,
            ]);

            if ($machineId) {
                $status = $this->statusFor($downtime->category, $downtime->reason_code);
                $this->closeOpenStatusLog($machineId);
                EquipmentStatusLog::query()->create(['machine_id' => $machineId, 'status' => $status, 'started_at' => now()]);
                Machine::query()->whereKey($machineId)->update(['status' => $status]);
            }

            if ($downtime->order_id !== null) {
                $this->events->record(
                    $downtime->order_id,
                    ProdEvent::TYPE_DOWNTIME_STARTED,
                    ['downtime_event_id' => $downtime->id, 'category' => $downtime->category, 'reason_code' => $downtime->reason_code],
                    $userId,
                    machineId: $machineId,
                );
            }

            return $downtime->refresh();
        });
    }

    public function end(DowntimeEvent $downtime, int $userId): DowntimeEvent
    {
        if ($downtime->ended_at !== null) {
            throw ValidationException::withMessages(['status' => 'This downtime event has already ended.']);
        }

        return DB::transaction(function () use ($downtime, $userId) {
            $downtime->update(['ended_at' => now(), 'ended_by' => $userId]);

            // MVP simplification: reverts to idle rather than whatever the machine was doing
            // before — this build tracks no "status prior to downtime" concept (§3D's status is
            // a flat current value, not a stack), same posture the spec's own denormalization
            // note accepts for mes_machines.status.
            if ($downtime->machine_id !== null) {
                $this->closeOpenStatusLog($downtime->machine_id);
                EquipmentStatusLog::query()->create(['machine_id' => $downtime->machine_id, 'status' => Machine::STATUS_IDLE, 'started_at' => now()]);
                Machine::query()->whereKey($downtime->machine_id)->update(['status' => Machine::STATUS_IDLE]);
            }

            if ($downtime->order_id !== null) {
                $this->events->record(
                    $downtime->order_id,
                    ProdEvent::TYPE_DOWNTIME_ENDED,
                    ['downtime_event_id' => $downtime->id],
                    $userId,
                    machineId: $downtime->machine_id,
                );
            }

            return $downtime->refresh();
        });
    }

    /**
     * §3M: "Unplanned downtime past a configurable duration threshold ... auto-creates a
     * maintenance request." Run periodically (see `mes:check-downtime-thresholds`,
     * `routes/console.php`) rather than checked at start (duration is unknown then) or only at
     * end (the point is alerting while it's still open) — same sweep posture
     * `SlaEscalationService::escalateBreachedSteps()` already uses for an identical
     * past-a-threshold-fire-once shape. `notified_at` is the once-only guard.
     *
     * @return int events notified this sweep
     */
    public function checkOpenThresholds(): int
    {
        $thresholdMinutes = (float) ($this->config->get('MES', 'DOWNTIME_THRESHOLD_MINUTES') ?? 60);
        $role = (string) ($this->config->get('MES', 'MAINTENANCE_CONTACT_ROLE') ?? 'ADMIN');

        $candidates = DowntimeEvent::query()->open()
            ->where('category', DowntimeEvent::CATEGORY_UNPLANNED)
            ->whereNull('notified_at')
            ->where('started_at', '<=', now()->subMinutes($thresholdMinutes))
            ->with(['machine:id,code,name', 'workCenter:id,code,name'])
            ->get();

        foreach ($candidates as $downtime) {
            $downtime->update(['notified_at' => now()]);

            $subject = $downtime->machine?->code ?? $downtime->workCenter?->code ?? "downtime #{$downtime->id}";

            NotificationRequested::dispatch(
                category: 'mes.downtime_threshold_exceeded',
                recipient: ['type' => 'role', 'role' => $role],
                payload: [
                    'downtime_event_id' => $downtime->id,
                    'machine_id' => $downtime->machine_id,
                    'work_center_id' => $downtime->work_center_id,
                    'reason_code' => $downtime->reason_code,
                    'started_at' => $downtime->started_at->toIso8601String(),
                ],
                subjectType: 'mes.mes_downtime_events',
                subjectId: $downtime->id,
                subject: "Unplanned downtime on {$subject} exceeding threshold",
                body: "Unplanned downtime ({$downtime->reason_code}) on {$subject} has been open since {$downtime->started_at->toDateTimeString()} — past the {$thresholdMinutes}-minute threshold.",
            );
        }

        return $candidates->count();
    }

    private function closeOpenStatusLog(int $machineId): void
    {
        EquipmentStatusLog::query()->where('machine_id', $machineId)->whereNull('ended_at')->update(['ended_at' => now()]);
    }

    private function statusFor(string $category, string $reasonCode): string
    {
        if ($category === DowntimeEvent::CATEGORY_PLANNED) {
            return $reasonCode === DowntimeEvent::REASON_SETUP ? Machine::STATUS_SETUP : Machine::STATUS_MAINTENANCE;
        }

        return Machine::STATUS_DOWN;
    }
}
