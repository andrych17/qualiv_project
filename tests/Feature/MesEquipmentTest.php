<?php

namespace Tests\Feature;

use App\Modules\MES\Models\Machine;
use App\Modules\MES\Models\Station;
use App\Modules\MES\Models\WorkCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpMES;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** MES_SPECS.md §3D — Equipment Master Data: Work Center -> Machine -> Station hierarchy. */
class MesEquipmentTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpMES;
    use SetsUpTenant;

    public function test_admin_can_crud_a_work_center(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $this->get('/mes/work-centers')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/WorkCenters/Index'));
        $this->get('/mes/work-centers/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/WorkCenters/Create'));

        $this->post('/mes/work-centers', [
            'code' => 'WC-01', 'name' => 'Assembly Line 1', 'area_line' => 'Line 1', 'type' => WorkCenter::TYPE_DISCRETE,
        ])->assertRedirect(route('mes.workCenters.index'));

        $workCenterId = null;
        $tenant->run(function () use (&$workCenterId) {
            $workCenterId = WorkCenter::query()->where('code', 'WC-01')->value('id');
        });

        $this->get('/mes/work-centers?search=Assembly')->assertOk()
            ->assertInertia(fn ($page) => $page->has('workCenters.data', 1));
        $this->get('/mes/work-centers?type='.WorkCenter::TYPE_PROCESS)->assertOk()
            ->assertInertia(fn ($page) => $page->has('workCenters.data', 0));

        $this->get("/mes/work-centers/{$workCenterId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/WorkCenters/Edit')->where('workCenter.code', 'WC-01'));

        $this->put("/mes/work-centers/{$workCenterId}", [
            'code' => 'WC-01', 'name' => 'Assembly Line 1 Renamed', 'area_line' => 'Line 1', 'type' => WorkCenter::TYPE_DISCRETE,
        ])->assertRedirect(route('mes.workCenters.index'));

        $tenant->run(function () use ($workCenterId) {
            $this->assertSame('Assembly Line 1 Renamed', WorkCenter::query()->find($workCenterId)->name);
        });

        $this->delete("/mes/work-centers/{$workCenterId}")->assertRedirect(route('mes.workCenters.index'));
        $tenant->run(function () use ($workCenterId) {
            $this->assertNull(WorkCenter::query()->find($workCenterId));
        });
    }

    public function test_work_center_store_and_update_reject_a_duplicate_code_and_invalid_type(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $otherId = null;
        $tenant->run(function () use (&$otherId) {
            $this->makeWorkCenter('WC-DUP');
            $otherId = $this->makeWorkCenter('WC-OTHER')->id;
        });

        $this->post('/mes/work-centers', [
            'code' => 'WC-DUP', 'name' => 'X', 'type' => 'not-a-type',
        ])->assertSessionHasErrors(['code', 'type']);

        $this->put("/mes/work-centers/{$otherId}", [
            'code' => 'WC-DUP', 'name' => 'X', 'type' => WorkCenter::TYPE_DISCRETE,
        ])->assertSessionHasErrors(['code']);
    }

    public function test_admin_can_bulk_destroy_work_centers(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = null;
        $tenant->run(function () use (&$ids) {
            $ids = [$this->makeWorkCenter('WC-A')->id, $this->makeWorkCenter('WC-B')->id];
        });

        $this->delete('/mes/work-centers/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () {
            $this->assertSame(0, WorkCenter::query()->count());
        });
    }

    public function test_admin_can_crud_a_machine(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $workCenterId = null;
        $tenant->run(function () use (&$workCenterId) {
            $workCenterId = $this->makeWorkCenter()->id;
        });

        $this->get('/mes/machines')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Machines/Index'));
        $this->get('/mes/machines/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Machines/Create'));

        $this->post('/mes/machines', [
            'work_center_id' => $workCenterId, 'code' => 'M-01', 'name' => 'Press 1', 'status' => Machine::STATUS_RUNNING,
        ])->assertRedirect(route('mes.machines.index'));

        $machineId = null;
        $tenant->run(function () use (&$machineId) {
            $machineId = Machine::query()->where('code', 'M-01')->value('id');
        });

        $this->get("/mes/machines?work_center_id={$workCenterId}&status=".Machine::STATUS_RUNNING)->assertOk()
            ->assertInertia(fn ($page) => $page->has('machines.data', 1));
        $this->get('/mes/machines?search=Press')->assertOk()
            ->assertInertia(fn ($page) => $page->has('machines.data', 1));

        $this->get("/mes/machines/{$machineId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Machines/Edit')->where('machine.code', 'M-01'));

        $this->put("/mes/machines/{$machineId}", [
            'work_center_id' => $workCenterId, 'code' => 'M-01', 'name' => 'Press 1', 'status' => Machine::STATUS_DOWN,
        ])->assertRedirect(route('mes.machines.index'));

        $tenant->run(function () use ($machineId) {
            $this->assertSame(Machine::STATUS_DOWN, Machine::query()->find($machineId)->status);
        });

        $this->delete("/mes/machines/{$machineId}")->assertRedirect(route('mes.machines.index'));
        $tenant->run(function () use ($machineId) {
            $this->assertNull(Machine::query()->find($machineId));
        });
    }

    public function test_machine_store_and_update_reject_duplicate_code_and_invalid_work_center(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $workCenterId = null;
        $otherId = null;
        $tenant->run(function () use (&$workCenterId, &$otherId) {
            $workCenter = $this->makeWorkCenter();
            $workCenterId = $workCenter->id;
            $this->makeMachine($workCenter, 'M-DUP');
            $otherId = $this->makeMachine($workCenter, 'M-OTHER')->id;
        });

        $this->post('/mes/machines', [
            'work_center_id' => 999999, 'code' => 'M-DUP', 'name' => 'X', 'status' => Machine::STATUS_IDLE,
        ])->assertSessionHasErrors(['code', 'work_center_id']);

        $this->put("/mes/machines/{$otherId}", [
            'work_center_id' => $workCenterId, 'code' => 'M-DUP', 'name' => 'X', 'status' => Machine::STATUS_IDLE,
        ])->assertSessionHasErrors(['code']);

        $this->put("/mes/machines/{$otherId}", [
            'work_center_id' => 999999, 'code' => 'M-OTHER', 'name' => 'X', 'status' => Machine::STATUS_IDLE,
        ])->assertSessionHasErrors(['work_center_id']);
    }

    public function test_admin_can_bulk_destroy_machines(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = null;
        $tenant->run(function () use (&$ids) {
            $workCenter = $this->makeWorkCenter();
            $ids = [$this->makeMachine($workCenter, 'M-A')->id, $this->makeMachine($workCenter, 'M-B')->id];
        });

        $this->delete('/mes/machines/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () {
            $this->assertSame(0, Machine::query()->count());
        });
    }

    public function test_admin_can_crud_a_station_hanging_off_a_work_center_or_a_machine(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $workCenterId = null;
        $machineId = null;
        $tenant->run(function () use (&$workCenterId, &$machineId) {
            $workCenter = $this->makeWorkCenter();
            $workCenterId = $workCenter->id;
            $machineId = $this->makeMachine($workCenter)->id;
        });

        $this->get('/mes/stations')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Stations/Index'));
        $this->get('/mes/stations/create')->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Stations/Create'));

        $this->post('/mes/stations', [
            'work_center_id' => $workCenterId, 'machine_id' => null, 'code' => 'ST-01', 'name' => 'Bench 1',
        ])->assertRedirect(route('mes.stations.index'));

        $this->post('/mes/stations', [
            'work_center_id' => null, 'machine_id' => $machineId, 'code' => 'ST-02', 'name' => 'Bench 2',
        ])->assertRedirect(route('mes.stations.index'));

        $stationId = null;
        $tenant->run(function () use (&$stationId) {
            $stationId = Station::query()->where('code', 'ST-01')->value('id');
        });

        $this->get('/mes/stations?search=Bench 1')->assertOk()
            ->assertInertia(fn ($page) => $page->has('stations.data', 1));

        $this->get("/mes/stations/{$stationId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('MES/Stations/Edit')->where('station.code', 'ST-01'));

        $this->put("/mes/stations/{$stationId}", [
            'work_center_id' => $workCenterId, 'machine_id' => $machineId, 'code' => 'ST-01', 'name' => 'Bench 1 Combined',
        ])->assertRedirect(route('mes.stations.index'));

        $tenant->run(function () use ($stationId, $machineId) {
            $this->assertSame($machineId, Station::query()->find($stationId)->machine_id);
        });

        $this->delete("/mes/stations/{$stationId}")->assertRedirect(route('mes.stations.index'));
        $tenant->run(function () use ($stationId) {
            $this->assertNull(Station::query()->find($stationId));
        });
    }

    public function test_station_store_and_update_reject_neither_owner_a_duplicate_code_and_invalid_references(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $workCenterId = null;
        $machineId = null;
        $otherId = null;
        $tenant->run(function () use (&$workCenterId, &$machineId, &$otherId) {
            $workCenter = $this->makeWorkCenter();
            $workCenterId = $workCenter->id;
            $machineId = $this->makeMachine($workCenter)->id;
            $this->makeStation('ST-DUP', ['work_center_id' => $workCenterId]);
            $otherId = $this->makeStation('ST-OTHER', ['work_center_id' => $workCenterId])->id;
        });

        $this->post('/mes/stations', [
            'work_center_id' => null, 'machine_id' => null, 'code' => 'ST-DUP', 'name' => 'X',
        ])->assertSessionHasErrors(['code', 'work_center_id']);

        $this->post('/mes/stations', [
            'work_center_id' => 999999, 'machine_id' => 999999, 'code' => 'ST-NEW', 'name' => 'X',
        ])->assertSessionHasErrors(['work_center_id', 'machine_id']);

        $this->put("/mes/stations/{$otherId}", [
            'work_center_id' => null, 'machine_id' => null, 'code' => 'ST-DUP', 'name' => 'X',
        ])->assertSessionHasErrors(['code', 'work_center_id']);

        $this->put("/mes/stations/{$otherId}", [
            'work_center_id' => 999999, 'machine_id' => $machineId, 'code' => 'ST-OTHER', 'name' => 'X',
        ])->assertSessionHasErrors(['work_center_id']);

        $this->put("/mes/stations/{$otherId}", [
            'work_center_id' => $workCenterId, 'machine_id' => 999999, 'code' => 'ST-OTHER', 'name' => 'X',
        ])->assertSessionHasErrors(['machine_id']);
    }

    public function test_admin_can_bulk_destroy_stations(): void
    {
        $tenant = $this->loginAsMesAdmin();

        $ids = null;
        $tenant->run(function () use (&$ids) {
            $workCenter = $this->makeWorkCenter();
            $ids = [
                $this->makeStation('ST-A', ['work_center_id' => $workCenter->id])->id,
                $this->makeStation('ST-B', ['work_center_id' => $workCenter->id])->id,
            ];
        });

        $this->delete('/mes/stations/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () {
            $this->assertSame(0, Station::query()->count());
        });
    }
}
