<?php

namespace App\Modules\MES\Data;

/**
 * MES_SPECS.md §3S — the canonical shape every protocol adapter normalizes its vendor-specific
 * payload into, before `IotIngestionService` writes it through the same tables operator-entered
 * data uses. Exactly one of two shapes per instance: a process parameter reading
 * (`batchPhaseId` + `processParameterId` + `value`) or a production event
 * (`orderId` + `eventType`) — never both, per `IotIngestionService::ingest()`'s own validation.
 */
final class IotReading
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public ?int $machineId,
        public ?int $batchPhaseId,
        public ?int $processParameterId,
        public ?float $value,
        public ?int $orderId,
        public ?string $eventType,
        public array $payload = [],
    ) {}
}
