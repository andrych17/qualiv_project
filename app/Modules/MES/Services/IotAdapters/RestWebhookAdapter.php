<?php

namespace App\Modules\MES\Services\IotAdapters;

use App\Modules\MES\Contracts\IotProtocolAdapter;
use App\Modules\MES\Data\IotReading;

/**
 * MES_SPECS.md §3S — the one concrete adapter this build ships. The REST payload already arrives
 * in the canonical shape (`IngestIotDataRequest` validated it before the job was even dispatched),
 * so this adapter's job is just the array-to-DTO mapping every adapter must do, not any real
 * protocol translation.
 *
 * Expected shape: `{"machine_id": 5, "readings": [{"batch_phase_id": 12,
 * "process_parameter_id": 3, "value": 101.5}, ...], "events": [{"order_id": 7,
 * "event_type": "downtime_started", "payload": {...}}, ...]}` — one gateway call maps to one
 * machine, per the field-gateway deployment model (one PLC/SCADA gateway per machine).
 */
class RestWebhookAdapter implements IotProtocolAdapter
{
    public function normalize(array $raw): array
    {
        $machineId = isset($raw['machine_id']) ? (int) $raw['machine_id'] : null;
        $items = [];

        foreach ($raw['readings'] ?? [] as $reading) {
            $items[] = new IotReading(
                machineId: $machineId,
                batchPhaseId: isset($reading['batch_phase_id']) ? (int) $reading['batch_phase_id'] : null,
                processParameterId: isset($reading['process_parameter_id']) ? (int) $reading['process_parameter_id'] : null,
                value: isset($reading['value']) ? (float) $reading['value'] : null,
                orderId: null,
                eventType: null,
            );
        }

        foreach ($raw['events'] ?? [] as $event) {
            $items[] = new IotReading(
                machineId: $machineId,
                batchPhaseId: null,
                processParameterId: null,
                value: null,
                orderId: isset($event['order_id']) ? (int) $event['order_id'] : null,
                eventType: $event['event_type'] ?? null,
                payload: $event['payload'] ?? [],
            );
        }

        return $items;
    }
}
