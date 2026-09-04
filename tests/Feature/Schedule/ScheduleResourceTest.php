<?php

namespace Tests\Feature\Schedule;

use App\Modules\Schedule\Models\Resource;
use App\Modules\Schedule\Models\ResourceType;
use App\Modules\Schedule\Models\SchedWorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpSchedule;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class ScheduleResourceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpSchedule;
    use SetsUpTenant;

    public function test_admin_can_crud_a_resource_with_working_hours(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $tenant->run(function () {
            $this->makeResourceType('ROOM', 'Room');
        });

        $this->get('/schedule/resources')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Resources/Index')
                ->has('resourceTypes', 1));

        $this->get('/schedule/resources/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Resources/Create')
                ->has('resourceTypes', 1));

        $typeId = null;
        $tenant->run(function () use (&$typeId) {
            $typeId = ResourceType::query()->value('id');
        });

        $this->post('/schedule/resources', [
            'resource_type_id' => $typeId,
            'name' => 'Conference Room A',
            'location_notes' => '2nd floor',
            'capacity' => 8,
            'working_hours' => [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
                ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
            ],
        ])->assertRedirect(route('schedule.resources.index'));

        $resourceId = null;
        $tenant->run(function () use (&$resourceId) {
            $resource = Resource::query()->where('name', 'Conference Room A')->first();
            $this->assertNotNull($resource);
            $this->assertSame(8, $resource->capacity);
            $this->assertSame(2, SchedWorkingHour::query()->where('resource_id', $resource->id)->count());
            $resourceId = $resource->id;
        });

        $this->get("/schedule/resources/{$resourceId}/edit")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Schedule/Resources/Edit')
                ->where('resource.name', 'Conference Room A')
                ->where('resource.working_hours.0.start_time', '09:00')
                ->where('resource.working_hours.0.end_time', '17:00'));

        // Update replaces working hours entirely (old rows removed, new ones inserted).
        $this->put("/schedule/resources/{$resourceId}", [
            'resource_type_id' => $typeId,
            'name' => 'Conference Room A (Renamed)',
            'location_notes' => '3rd floor',
            'capacity' => 10,
            'is_active' => true,
            'working_hours' => [
                ['day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '16:00'],
            ],
        ])->assertRedirect(route('schedule.resources.index'));

        $tenant->run(function () use ($resourceId) {
            $resource = Resource::query()->find($resourceId);
            $this->assertSame('Conference Room A (Renamed)', $resource->name);
            $this->assertSame(10, $resource->capacity);
            $hours = SchedWorkingHour::query()->where('resource_id', $resourceId)->get();
            $this->assertCount(1, $hours);
            $this->assertSame(3, $hours->first()->day_of_week);
        });

        // Destroy deactivates rather than deletes (FK-referenced by past bookings).
        $this->delete("/schedule/resources/{$resourceId}")
            ->assertRedirect(route('schedule.resources.index'));

        $tenant->run(function () use ($resourceId) {
            $resource = Resource::query()->find($resourceId);
            $this->assertNotNull($resource);
            $this->assertFalse($resource->is_active);
        });
    }

    public function test_resource_index_filters_by_search_type_status_and_sort(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $tenant->run(function () {
            $roomType = $this->makeResourceType('ROOM', 'Room');
            $vehicleType = $this->makeResourceType('VEHICLE', 'Vehicle');
            $this->makeResource($roomType, 'Alpha Room')->update(['is_active' => true]);
            $this->makeResource($vehicleType, 'Beta Van')->update(['is_active' => false]);
        });

        $this->get('/schedule/resources?search=Alpha')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('resources.data', 1));

        $this->get('/schedule/resources?status=inactive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('resources.data', 1)
                ->where('resources.data.0.name', 'Beta Van'));

        $this->get('/schedule/resources?status=active')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('resources.data', 1)
                ->where('resources.data.0.name', 'Alpha Room'));

        $vehicleTypeId = null;
        $tenant->run(function () use (&$vehicleTypeId) {
            $vehicleTypeId = ResourceType::query()->where('code', 'VEHICLE')->value('id');
        });

        $this->get("/schedule/resources?resource_type_id={$vehicleTypeId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('resources.data', 1)
                ->where('resources.data.0.name', 'Beta Van'));

        $this->get('/schedule/resources?sort=name&direction=desc&per_page=5')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resources.data.0.name', 'Beta Van')
                ->where('resources.data.1.name', 'Alpha Room'));
    }

    public function test_store_resource_rejects_invalid_type_and_bad_working_hours(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $this->post('/schedule/resources', [
            'resource_type_id' => 999999,
            'name' => 'Ghost Room',
        ])->assertSessionHasErrors(['resource_type_id']);

        $this->post('/schedule/resources', [])->assertSessionHasErrors(['resource_type_id', 'name']);

        $typeId = null;
        $tenant->run(function () use (&$typeId) {
            $typeId = $this->makeResourceType('ROOM', 'Room')->id;
        });

        $this->post('/schedule/resources', [
            'resource_type_id' => $typeId,
            'name' => 'Bad Hours Room',
            'working_hours' => [
                ['day_of_week' => 1, 'start_time' => '17:00', 'end_time' => '09:00'],
            ],
        ])->assertSessionHasErrors(['working_hours.0.end_time']);
    }

    public function test_update_resource_rejects_invalid_type_and_bad_working_hours(): void
    {
        $tenant = $this->loginAsScheduleAdmin();

        $resourceId = null;
        $tenant->run(function () use (&$resourceId) {
            $resourceId = $this->makeResource()->id;
        });

        $this->put("/schedule/resources/{$resourceId}", [
            'resource_type_id' => 999999,
            'name' => 'Still Bad',
        ])->assertSessionHasErrors(['resource_type_id']);

        $typeId = null;
        $tenant->run(function () use (&$typeId) {
            $typeId = ResourceType::query()->value('id');
        });

        $this->put("/schedule/resources/{$resourceId}", [
            'resource_type_id' => $typeId,
            'name' => 'Still Bad',
            'working_hours' => [
                ['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '10:00'],
            ],
        ])->assertSessionHasErrors(['working_hours.0.end_time']);
    }
}
