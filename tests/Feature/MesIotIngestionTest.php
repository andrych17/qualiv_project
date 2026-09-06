<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MES\Contracts\IotProtocolAdapter;
use App\Modules\MES\Data\IotReading;
use App\Modules\MES\Jobs\ProcessIotIngestionJob;
use App\Modules\MES\Models\BatchParameterReading;
use App\Modules\MES\Models\BatchPhase;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\MesBatch;
use App\Modules\MES\Models\ProcessParameter;
use App\Modules\MES\Models\ProcessPhase;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Services\IotAdapters\RestWebhookAdapter;
use App\Modules\MES\Services\IotIngestionService;
use App\Modules\PP\Models\Bom;
use App\Modules\PP\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3S — IoT / PLC Integration (Phase 3, built now per explicit override). Bearer
 * token + `X-Tenant-Id` header, same shape `LegalFieldVisitApiTest` already exercises. The
 * `QUEUE_CONNECTION=sync` on this host still runs `ProcessIotIngestionJob` through the real
 * queue/dispatch machinery inline (see the job's own docblock) — assert on the DB rows it
 * wrote, not on `Queue::assertPushed`, so the test proves the write actually happened.
 */
class MesIotIngestionTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    private function bearerHeaders(): array
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
            'tenant_id' => '001',
        ])->assertOk();

        return ['Authorization' => 'Bearer '.$login->json('token'), 'X-Tenant-Id' => '001'];
    }

    public function test_ingesting_a_parameter_reading_writes_the_reading_and_a_ledger_event(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $machineId = null;
        $batchPhaseId = null;
        $parameterId = null;
        $tenant->run(function () use (&$machineId, &$batchPhaseId, &$parameterId) {
            $workCenter = WorkCenter::query()->create(['code' => 'WC-IOT', 'name' => 'IoT Line', 'type' => 'process']);
            $machineId = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-IOT', 'name' => 'IoT Reactor', 'status' => 'running'])->id;

            $uom = Uom::query()->create(['code' => 'KG', 'name' => 'Kilograms']);
            $product = Product::query()->create([
                'sku' => 'IOT-01', 'name' => 'IoT Widget', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $recipe = Recipe::query()->create(['product_id' => $product->id, 'version' => 1, 'batch_size' => 100, 'is_active' => true]);
            $order = ProdOrder::query()->create([
                'order_number' => 'MO-IOT-1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 100, 'status' => ProdOrder::STATUS_IN_PROGRESS,
            ]);
            $phase = ProcessPhase::query()->create(['recipe_id' => $recipe->id, 'seq' => 1, 'phase_name' => 'React', 'work_center_id' => $workCenter->id]);
            $parameterId = ProcessParameter::query()->create(['process_phase_id' => $phase->id, 'parameter_code' => 'TEMP', 'min_value' => 10, 'max_value' => 20])->id;

            $batch = MesBatch::query()->create(['order_id' => $order->id, 'batch_number' => 'B-IOT-1', 'recipe_id' => $recipe->id, 'status' => MesBatch::STATUS_RUNNING, 'planned_qty' => 100]);
            $batchPhaseId = BatchPhase::query()->create(['batch_id' => $batch->id, 'process_phase_id' => $phase->id, 'seq' => 1, 'status' => BatchPhase::STATUS_RUNNING])->id;
        });

        $headers = $this->bearerHeaders();

        $this->postJson('/api/v1/mes/iot/ingest', [
            'machine_id' => $machineId,
            'readings' => [
                ['batch_phase_id' => $batchPhaseId, 'process_parameter_id' => $parameterId, 'value' => 25.5],
            ],
        ], $headers)->assertStatus(202);

        $tenant->run(function () use ($batchPhaseId, $parameterId, $machineId) {
            $reading = BatchParameterReading::query()->where('batch_phase_id', $batchPhaseId)->first();
            $this->assertNotNull($reading);
            $this->assertSame($parameterId, $reading->process_parameter_id);
            $this->assertEquals(25.5, (float) $reading->value);
            $this->assertSame($machineId, $reading->machine_id);

            $event = ProdEvent::query()->where('operation_ref', $batchPhaseId)->where('event_type', ProdEvent::TYPE_PARAMETER_RECORDED)->first();
            $this->assertNotNull($event);
            $this->assertTrue($event->payload['out_of_range']);
            $this->assertSame('iot', $event->payload['source']);
        });
    }

    public function test_ingesting_an_event_writes_directly_to_the_ledger(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $orderId = null;
        $machineId = null;
        $tenant->run(function () use (&$orderId, &$machineId) {
            $workCenter = WorkCenter::query()->create(['code' => 'WC-IOT-2', 'name' => 'IoT Line 2', 'type' => 'discrete']);
            $machineId = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-IOT-2', 'name' => 'IoT Press', 'status' => 'running'])->id;

            $uom = Uom::query()->create(['code' => 'PCS', 'name' => 'Pieces']);
            $product = Product::query()->create([
                'sku' => 'IOT-02', 'name' => 'IoT Widget 2', 'base_uom_id' => $uom->id,
                'costing_method' => Product::COSTING_FIFO, 'tracking_mode' => Product::TRACKING_NONE,
            ]);
            $bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
            $orderId = ProdOrder::query()->create([
                'order_number' => 'MO-IOT-2', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_ASSEMBLY,
                'bom_id' => $bom->id, 'qty' => 1, 'status' => ProdOrder::STATUS_IN_PROGRESS,
            ])->id;
        });

        $headers = $this->bearerHeaders();

        $this->postJson('/api/v1/mes/iot/ingest', [
            'machine_id' => $machineId,
            'events' => [
                ['order_id' => $orderId, 'event_type' => ProdEvent::TYPE_DOWNTIME_STARTED, 'payload' => ['reason' => 'sensor_fault']],
            ],
        ], $headers)->assertStatus(202);

        $tenant->run(function () use ($orderId, $machineId) {
            $event = ProdEvent::query()->where('order_id', $orderId)->where('event_type', ProdEvent::TYPE_DOWNTIME_STARTED)->first();
            $this->assertNotNull($event);
            $this->assertSame($machineId, $event->machine_id);
            $this->assertSame('sensor_fault', $event->payload['reason']);
        });
    }

    public function test_an_empty_payload_is_rejected(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $headers = $this->bearerHeaders();

        $this->postJson('/api/v1/mes/iot/ingest', [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payload');
    }

    public function test_ingestion_requires_a_bearer_token(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->postJson('/api/v1/mes/iot/ingest', ['readings' => []], ['X-Tenant-Id' => '001'])
            ->assertUnauthorized();
    }

    public function test_ingestion_rejects_an_invalid_machine_batch_phase_parameter_and_order_id(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $headers = $this->bearerHeaders();

        $this->postJson('/api/v1/mes/iot/ingest', [
            'machine_id' => 999999,
            'readings' => [['batch_phase_id' => 999999, 'process_parameter_id' => 999999, 'value' => 1]],
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['machine_id', 'readings.0.batch_phase_id', 'readings.0.process_parameter_id']);

        $this->postJson('/api/v1/mes/iot/ingest', [
            'events' => [['order_id' => 999999, 'event_type' => 'downtime_started']],
        ], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['events.0.order_id']);
    }

    /**
     * IotIngestionService's own "no items" and "neither a reading nor an event shape" guards are
     * unreachable via HTTP — IngestIotDataRequest already requires at least one non-empty
     * readings/events entry with every sub-field present, so RestWebhookAdapter::normalize()
     * always returns well-formed items. Reachable only by calling the service directly.
     */
    public function test_ingestion_service_guards_are_unreachable_via_http_and_covered_directly(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $service = app(IotIngestionService::class);
            $adapter = new RestWebhookAdapter;
            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');

            $this->expectException(ValidationException::class);
            $service->ingest([], $adapter, $userId);
        });
    }

    public function test_ingestion_service_rejects_a_malformed_item_directly(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $service = app(IotIngestionService::class);
            $userId = User::query()->where('email', 'admin@nusaevo.com')->value('id');

            // Bypasses RestWebhookAdapter's own field mapping entirely — a bare stub adapter that
            // hands back one item with none of the reading/event fields set.
            $adapter = new class implements IotProtocolAdapter
            {
                public function normalize(array $raw): array
                {
                    return [new IotReading(machineId: null, batchPhaseId: null, processParameterId: null, value: null, orderId: null, eventType: null)];
                }
            };

            $this->expectException(ValidationException::class);
            $service->ingest(['probe' => true], $adapter, $userId);
        });
    }

    /** ProcessIotIngestionJob::failed() is Laravel's own queue-failure callback — never invoked by a normal successful dispatch, only by the queue worker after retries are exhausted. Call it directly to cover the log line. */
    public function test_the_job_logs_a_failure_when_it_gives_up(): void
    {
        $job = new ProcessIotIngestionJob(['readings' => []], RestWebhookAdapter::class, 1);

        Log::shouldReceive('error')->once()->with('MES IoT ingestion job failed', \Mockery::type('array'));

        $job->failed(new \Exception('simulated failure'));
    }
}
