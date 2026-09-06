<?php

namespace Tests\Feature;

use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\EquipmentStatusLog;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\ProdEvent;
use App\Modules\MES\Models\ProdOrder;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Services\DowntimeService;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * MES_SPECS.md §3M — Equipment Status & Downtime: start/end over HTTP denormalizes
 * `mes_machines.status` and `mes_equipment_status_logs`, and the threshold sweep auto-notifies
 * the maintenance contact role exactly once per open event.
 */
class MesDowntimeTrackingTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_starting_and_ending_downtime_over_http_tracks_machine_status_and_the_status_log(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $machineId = null;
        $tenant->run(function () use (&$machineId) {
            $workCenter = WorkCenter::query()->create(['code' => 'WC-1', 'name' => 'Line 1', 'type' => 'discrete']);
            $machineId = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-1', 'name' => 'Press 1', 'status' => 'idle'])->id;
        });

        $this->post('/mes/downtime-events', [
            'machine_id' => $machineId,
            'category' => DowntimeEvent::CATEGORY_UNPLANNED,
            'reason_code' => DowntimeEvent::REASON_MECHANICAL,
        ])->assertRedirect('/mes/downtime-events');

        $downtimeId = null;
        $tenant->run(function () use (&$downtimeId, $machineId) {
            $downtime = DowntimeEvent::query()->where('machine_id', $machineId)->first();
            $this->assertNotNull($downtime);
            $this->assertNull($downtime->ended_at);
            $downtimeId = $downtime->id;

            $this->assertSame(Machine::STATUS_DOWN, Machine::query()->find($machineId)->status);

            $log = EquipmentStatusLog::query()->where('machine_id', $machineId)->whereNull('ended_at')->first();
            $this->assertNotNull($log);
            $this->assertSame(Machine::STATUS_DOWN, $log->status);
            $this->assertSame($machineId, $log->machine->id);
        });

        // A second open downtime on the same machine is rejected.
        $this->post('/mes/downtime-events', [
            'machine_id' => $machineId,
            'category' => DowntimeEvent::CATEGORY_UNPLANNED,
            'reason_code' => DowntimeEvent::REASON_ELECTRICAL,
        ])->assertSessionHasErrors('machine_id');

        $this->post("/mes/downtime-events/{$downtimeId}/end")->assertRedirect();

        $tenant->run(function () use ($downtimeId, $machineId) {
            $downtime = DowntimeEvent::query()->find($downtimeId);
            $this->assertNotNull($downtime->ended_at);
            $this->assertNotNull($downtime->startedBy);
            $this->assertNotNull($downtime->endedBy);

            $this->assertSame(Machine::STATUS_IDLE, Machine::query()->find($machineId)->status);
            $this->assertSame(1, DowntimeEvent::query()->where('machine_id', $machineId)->count());

            $openLogCount = EquipmentStatusLog::query()->where('machine_id', $machineId)->whereNull('ended_at')->count();
            $this->assertSame(1, $openLogCount); // idle log opened after downtime closed
        });

        // Ending an already-ended event is rejected.
        $this->post("/mes/downtime-events/{$downtimeId}/end")->assertSessionHasErrors('status');
    }

    public function test_downtime_scoped_to_a_bare_work_center_writes_no_machine_status(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $workCenterId = null;
        $tenant->run(function () use (&$workCenterId) {
            $workCenterId = WorkCenter::query()->create(['code' => 'WC-2', 'name' => 'Line 2', 'type' => 'discrete'])->id;
        });

        $this->post('/mes/downtime-events', [
            'work_center_id' => $workCenterId,
            'category' => DowntimeEvent::CATEGORY_UNPLANNED,
            'reason_code' => DowntimeEvent::REASON_MATERIAL_SHORTAGE,
        ])->assertRedirect('/mes/downtime-events');

        $tenant->run(function () use ($workCenterId) {
            $downtime = DowntimeEvent::query()->where('work_center_id', $workCenterId)->first();
            $this->assertNotNull($downtime);
            $this->assertNull($downtime->machine_id);
            $this->assertSame(0, EquipmentStatusLog::query()->count());
        });
    }

    public function test_neither_machine_nor_work_center_is_rejected(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->post('/mes/downtime-events', [
            'category' => DowntimeEvent::CATEGORY_UNPLANNED,
            'reason_code' => DowntimeEvent::REASON_OPERATOR,
        ])->assertSessionHasErrors('machine_id');
    }

    public function test_a_planned_reason_is_rejected_for_an_unplanned_category(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);
        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $machineId = null;
        $tenant->run(function () use (&$machineId) {
            $workCenter = WorkCenter::query()->create(['code' => 'WC-3', 'name' => 'Line 3', 'type' => 'discrete']);
            $machineId = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-3', 'name' => 'Press 3', 'status' => 'idle'])->id;
        });

        $this->post('/mes/downtime-events', [
            'machine_id' => $machineId,
            'category' => DowntimeEvent::CATEGORY_UNPLANNED,
            'reason_code' => DowntimeEvent::REASON_SETUP,
        ])->assertSessionHasErrors('reason_code');
    }

    public function test_threshold_sweep_notifies_the_maintenance_role_exactly_once(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            Event::fake([NotificationRequested::class]);

            $workCenter = WorkCenter::query()->create(['code' => 'WC-4', 'name' => 'Line 4', 'type' => 'discrete']);
            $machine = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-4', 'name' => 'Press 4', 'status' => 'idle']);

            // Started 90 minutes ago — past the default 60-minute threshold.
            $downtime = DowntimeEvent::query()->create([
                'machine_id' => $machine->id,
                'category' => DowntimeEvent::CATEGORY_UNPLANNED,
                'reason_code' => DowntimeEvent::REASON_MECHANICAL,
                'started_at' => now()->subMinutes(90),
            ]);

            $notified = app(DowntimeService::class)->checkOpenThresholds();
            $this->assertSame(1, $notified);

            Event::assertDispatched(NotificationRequested::class, function (NotificationRequested $event) use ($downtime) {
                return $event->category === 'mes.downtime_threshold_exceeded'
                    && $event->recipient === ['type' => 'role', 'role' => 'ADMIN']
                    && $event->payload['downtime_event_id'] === $downtime->id;
            });

            $this->assertNotNull($downtime->refresh()->notified_at);

            // A second sweep does not re-notify the same open event.
            $notifiedAgain = app(DowntimeService::class)->checkOpenThresholds();
            $this->assertSame(0, $notifiedAgain);
        });
    }

    public function test_downtime_index_lists_and_filters_with_relations_and_options(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $workCenter = $this->makeWorkCenter('WC-DT1');
            $machine = $this->makeMachine($workCenter, 'M-DT1');
            $product = $this->makeProduct('DT-1');
            $recipe = $this->makeRecipe($product->id);
            $order = ProdOrder::query()->create([
                'order_number' => 'WO-DT-1', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            DowntimeEvent::query()->create([
                'machine_id' => $machine->id, 'work_center_id' => $workCenter->id, 'order_id' => $order->id,
                'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
                'started_at' => now()->subMinutes(30),
            ]);
            DowntimeEvent::query()->create([
                'machine_id' => $machine->id, 'category' => DowntimeEvent::CATEGORY_PLANNED, 'reason_code' => DowntimeEvent::REASON_MAINTENANCE,
                'started_at' => now()->subMinutes(90), 'ended_at' => now()->subMinutes(60),
            ]);

            $ids = ['machine' => $machine->id, 'workCenter' => $workCenter->id, 'order' => $order->order_number];
        });

        // default sort (started_at desc) puts the more-recent, still-open, order-linked event first.
        $this->get('/mes/downtime-events')->assertInertia(fn (Assert $page) => $page
            ->has('events.data', 2)
            ->where('openCount', 1)
            ->has('machines', 1)
            ->has('workCenters', 1)
            ->where('events.data.0.order_number', $ids['order'])
        );

        $this->get('/mes/downtime-events?status=open')->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
        $this->get('/mes/downtime-events?status=closed')->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
        $this->get('/mes/downtime-events?category=planned')->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
        $this->get("/mes/downtime-events?machine_id={$ids['machine']}")->assertInertia(fn (Assert $page) => $page->has('events.data', 2));
        $this->get("/mes/downtime-events?work_center_id={$ids['workCenter']}")->assertInertia(fn (Assert $page) => $page->has('events.data', 1));
        $this->get('/mes/downtime-events?sort=category&direction=asc')->assertInertia(fn (Assert $page) => $page->has('events.data', 2));
    }

    public function test_downtime_store_validation_rejects_invalid_machine_work_center_and_order_ids(): void
    {
        $this->loginAsMesAdmin();

        $this->post('/mes/downtime-events', [
            'machine_id' => 999999, 'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
        ])->assertSessionHasErrors('machine_id');

        $this->post('/mes/downtime-events', [
            'work_center_id' => 999999, 'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
        ])->assertSessionHasErrors('work_center_id');

        $tenant = $this->loginAsMesAdmin();
        $machineId = null;
        $tenant->run(function () use (&$machineId) {
            $machineId = $this->makeMachine($this->makeWorkCenter('WC-DT2'), 'M-DT2')->id;
        });

        $this->post('/mes/downtime-events', [
            'machine_id' => $machineId, 'order_id' => 999999,
            'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
        ])->assertSessionHasErrors('order_id');
    }

    public function test_a_mechanical_reason_is_rejected_for_a_planned_category(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $machineId = null;
        $tenant->run(function () use (&$machineId) {
            $machineId = $this->makeMachine($this->makeWorkCenter('WC-DT3'), 'M-DT3')->id;
        });

        $this->post('/mes/downtime-events', [
            'machine_id' => $machineId, 'category' => DowntimeEvent::CATEGORY_PLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
        ])->assertSessionHasErrors('reason_code');
    }

    public function test_downtime_against_an_order_writes_downtime_started_and_ended_ledger_events(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $workCenter = $this->makeWorkCenter('WC-DT4');
            $machine = $this->makeMachine($workCenter, 'M-DT4');
            $product = $this->makeProduct('DT-4');
            $recipe = $this->makeRecipe($product->id);
            $order = ProdOrder::query()->create([
                'order_number' => 'WO-DT-4', 'product_id' => $product->id, 'production_model' => ProdOrder::MODEL_PROCESS,
                'recipe_id' => $recipe->id, 'qty' => 1, 'uom_code' => 'PCS', 'status' => ProdOrder::STATUS_RELEASED,
            ]);

            $ids = ['machine' => $machine->id, 'order' => $order->id];
        });

        $this->post('/mes/downtime-events', [
            'machine_id' => $ids['machine'], 'order_id' => $ids['order'],
            'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
        ])->assertSessionHasNoErrors();

        $downtimeId = null;
        $tenant->run(function () use (&$downtimeId, $ids) {
            $downtimeId = DowntimeEvent::query()->where('order_id', $ids['order'])->value('id');
            $this->assertSame(1, ProdEvent::query()->where('order_id', $ids['order'])->where('event_type', ProdEvent::TYPE_DOWNTIME_STARTED)->count());
        });

        $this->post("/mes/downtime-events/{$downtimeId}/end")->assertSessionHasNoErrors();

        $tenant->run(function () use ($ids) {
            $this->assertSame(1, ProdEvent::query()->where('order_id', $ids['order'])->where('event_type', ProdEvent::TYPE_DOWNTIME_ENDED)->count());
        });
    }

    public function test_planned_downtime_with_a_maintenance_reason_sets_machine_status_to_maintenance(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $machineId = null;
        $tenant->run(function () use (&$machineId) {
            $machineId = $this->makeMachine($this->makeWorkCenter('WC-DT5'), 'M-DT5')->id;
        });

        $this->post('/mes/downtime-events', [
            'machine_id' => $machineId, 'category' => DowntimeEvent::CATEGORY_PLANNED, 'reason_code' => DowntimeEvent::REASON_MAINTENANCE,
        ])->assertSessionHasNoErrors();

        $tenant->run(function () use ($machineId) {
            $this->assertSame(Machine::STATUS_MAINTENANCE, Machine::query()->find($machineId)->status);
        });
    }

    /** `mes:check-downtime-thresholds` — the console entry point CheckOpenThresholds already gets exercised directly through; nothing runs the Artisan command itself elsewhere. */
    public function test_downtime_threshold_command_runs_within_tenant_context_and_notifies(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $tenant->run(function () {
            $workCenter = WorkCenter::query()->create(['code' => 'WC-DT6', 'name' => 'Line 6', 'type' => 'discrete']);
            $machine = Machine::query()->create(['work_center_id' => $workCenter->id, 'code' => 'M-DT6', 'name' => 'Press 6', 'status' => 'idle']);

            DowntimeEvent::query()->create([
                'machine_id' => $machine->id, 'category' => DowntimeEvent::CATEGORY_UNPLANNED, 'reason_code' => DowntimeEvent::REASON_MECHANICAL,
                'started_at' => now()->subMinutes(90),
            ]);

            $this->artisan('mes:check-downtime-thresholds')->assertExitCode(0);

            $this->assertNotNull(DowntimeEvent::query()->where('machine_id', $machine->id)->value('notified_at'));
        });
    }
}
