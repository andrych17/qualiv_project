<?php

namespace App\Modules\MES\Contracts;

use App\Modules\MES\Data\IotReading;

/**
 * MES_SPECS.md §3S — "Integration layer only, never hard-coded machine protocol handling inside
 * MES's own services." `IotIngestionService` and its queued job depend on this interface, never
 * on a concrete protocol. `RestWebhookAdapter` is the one real implementation this build ships
 * (REST is one of the spec's own listed protocols and needs no new Composer dependency); future
 * MQTT/OPC-UA/Modbus adapters plug in the same way once this tenant actually has that hardware
 * and a client library to talk to it — not stubbed here as fake implementations.
 */
interface IotProtocolAdapter
{
    /**
     * @param  array<string, mixed>  $raw
     * @return list<IotReading>
     */
    public function normalize(array $raw): array;
}
