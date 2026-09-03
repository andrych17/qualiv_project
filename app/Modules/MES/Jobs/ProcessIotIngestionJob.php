<?php

namespace App\Modules\MES\Jobs;

use App\Modules\MES\Services\IotIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MES_SPECS.md §3S: "ingestion happens through a queued job, never inline in the request" — the
 * API controller validates and dispatches; this job is the only thing that actually writes.
 * Tenant-scoped automatically (stancl's queue bootstrapper tags the job with the tenant it was
 * dispatched from, per CLAUDE.md §4), same as every other tenant-aware queued job in this
 * codebase — no explicit tenant plumbing needed here.
 *
 * Only the raw payload + adapter class name + acting user id are serialized (plain scalars/array,
 * no models) — same "minimal serializable constructor args" posture as
 * `WNE\Jobs\SendNotificationDeliveryJob`.
 */
class ProcessIotIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public array $payload,
        public string $adapterClass,
        public int $userId,
    ) {}

    public function handle(IotIngestionService $service): void
    {
        $adapter = app($this->adapterClass);
        $service->ingest($this->payload, $adapter, $this->userId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('MES IoT ingestion job failed', ['error' => $exception->getMessage(), 'adapter' => $this->adapterClass, 'user_id' => $this->userId]);
    }
}
