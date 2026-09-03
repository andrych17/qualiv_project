<?php

namespace Tests\Feature;

use App\Modules\MES\Models\DowntimeEvent;
use App\Modules\MES\Models\EquipmentStatusLog;
use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\WorkCenter;
use App\Modules\MES\Services\DowntimeService;
use App\Modules\WNE\Events\NotificationRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
}
