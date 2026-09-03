<?php

namespace App\Modules\MES\Services;

use App\Modules\MES\Models\ProdEvent;

/**
 * MES_SPECS.md §3C — the single append-only write path every execution engine uses (§5
 * Technical Notes). Every other engine (§3G–§3M, not yet built) calls `record()` rather than
 * writing `mes_prod_events` directly, so this stays the one place that discipline is enforced.
 */
class ProdEventService
{
    /** @param  array<string, mixed>  $payload */
    public function record(
        int $orderId,
        string $eventType,
        array $payload,
        int $userId,
        ?int $machineId = null,
        ?int $batchId = null,
        ?int $operationRef = null,
    ): ProdEvent {
        return ProdEvent::query()->create([
            'order_id' => $orderId,
            'batch_id' => $batchId,
            'operation_ref' => $operationRef,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => now(),
            'user_id' => $userId,
            'machine_id' => $machineId,
        ]);
    }
}
