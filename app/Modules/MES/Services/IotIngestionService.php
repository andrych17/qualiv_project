<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Contracts\IotProtocolAdapter;
use App\Modules\MES\Data\IotReading;
use App\Modules\MES\Models\BatchParameterReading;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProdEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MES_SPECS.md §3S — the one write path a normalized IoT reading goes through, same discipline
 * `ProdEventService` already enforces for the ledger itself: "one write path regardless of
 * whether a human or a machine produced the event." A parameter reading lands in
 * `mes_batch_parameter_readings` (§3I) exactly like `BatchExecutionService::completePhase()`
 * writes it — this just isn't gated on phase completion, so a PLC can stream readings
 * continuously through a running phase, not only at its end. A production event lands in
 * `mes_prod_events` (§3C) through `ProdEventService::record()`, unchanged.
 *
 * `ProdEventService::record()`'s `order_id` is NOT NULL, so an event with no resolvable order
 * is rejected here rather than silently dropped or force-null — same decision `DowntimeService`
 * made for orderless equipment downtime, just enforced at the boundary instead of by omission.
 */
class IotIngestionService
{
    public function __construct(protected ProdEventService $events) {}

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{readings_recorded: int, events_recorded: int}
     */
    public function ingest(array $rawPayload, IotProtocolAdapter $adapter, int $userId): array
    {
        $items = $adapter->normalize($rawPayload);

        if (empty($items)) {
            throw ValidationException::withMessages(['payload' => 'No readings or events found in the payload.']);
        }

        $readingsRecorded = 0;
        $eventsRecorded = 0;

        DB::transaction(function () use ($items, $userId, &$readingsRecorded, &$eventsRecorded) {
            foreach ($items as $item) {
                if ($item->batchPhaseId !== null && $item->processParameterId !== null && $item->value !== null) {
                    $this->recordReading($item, $userId);
                    $readingsRecorded++;

                    continue;
                }

                if ($item->orderId !== null && $item->eventType !== null) {
                    $this->events->record($item->orderId, $item->eventType, $item->payload, $userId, machineId: $item->machineId);
                    $eventsRecorded++;

                    continue;
                }

                throw ValidationException::withMessages([
                    'payload' => 'Each item must be a parameter reading (batch_phase_id + process_parameter_id + value) or an event (order_id + event_type).',
                ]);
            }
        });

        return ['readings_recorded' => $readingsRecorded, 'events_recorded' => $eventsRecorded];
    }

    private function recordReading(IotReading $item, int $userId): void
    {
        $phase = BatchPhase::query()->with('batch', 'processPhase')->findOrFail($item->batchPhaseId);
        $parameter = ProcessParameter::query()->findOrFail($item->processParameterId);
        $value = (float) $item->value;
        $outOfRange = ($parameter->min_value !== null && $value < (float) $parameter->min_value)
            || ($parameter->max_value !== null && $value > (float) $parameter->max_value);

        BatchParameterReading::query()->create([
            'batch_phase_id' => $phase->id,
            'process_parameter_id' => $parameter->id,
            'value' => $value,
            'recorded_at' => now(),
            'recorded_by' => $userId,
            'machine_id' => $item->machineId,
        ]);

        $this->events->record(
            $phase->batch->order_id,
            ProdEvent::TYPE_PARAMETER_RECORDED,
            ['parameter_code' => $parameter->parameter_code, 'value' => $value, 'out_of_range' => $outOfRange, 'source' => 'iot'],
            $userId,
            machineId: $item->machineId,
            operationRef: $phase->id,
            batchId: $phase->batch_id,
        );
    }
}
