<?php

namespace Tests\Feature;

use App\Modules\PP\Models\Resource;
use App\Modules\PP\Models\ResourceGroup;
use App\Modules\PP\Models\ResourceGroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** PP_SPECS.md §3E — Resource & Resource Group Reference CRUD, including the polymorphic-by-type member validation. */
class PPResourceTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_resource_crud_and_unique_code(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $this->get('/pp/resources')->assertOk()->assertInertia(fn ($page) => $page->component('PP/Resources/Index'));
        $this->get('/pp/resources/create')->assertOk()->assertInertia(fn ($page) => $page->component('PP/Resources/Create'));

        $this->post('/pp/resources', [
            'type' => 'tank', 'code' => 'TANK-01', 'name' => 'Mixing Tank 1', 'capacity' => 500, 'uom_code' => 'L',
        ])->assertRedirect('/pp/resources');

        $resourceId = null;
        $tenant->run(function () use (&$resourceId) {
            $resource = Resource::query()->where('code', 'TANK-01')->first();
            $this->assertNotNull($resource);
            $this->assertSame('tank', $resource->type);
            $this->assertEquals(500, $resource->capacity);
            $resourceId = $resource->id;
        });

        // Duplicate code is rejected.
        $this->post('/pp/resources', [
            'type' => 'tank', 'code' => 'TANK-01', 'name' => 'Duplicate',
        ])->assertSessionHasErrors('code');

        $this->get("/pp/resources/{$resourceId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('PP/Resources/Edit'));

        $this->put("/pp/resources/{$resourceId}", [
            'type' => 'tank', 'code' => 'TANK-01', 'name' => 'Mixing Tank 1 (renamed)', 'capacity' => 750,
        ])->assertRedirect('/pp/resources');

        $tenant->run(function () use ($resourceId) {
            $this->assertEquals(750, Resource::query()->find($resourceId)->capacity);
        });

        $this->delete("/pp/resources/{$resourceId}")->assertRedirect('/pp/resources');
        $tenant->run(function () use ($resourceId) {
            $this->assertNull(Resource::query()->find($resourceId));
        });
    }

    public function test_resource_group_crud_with_members(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', ['email' => 'admin@nusaevo.com', 'password' => 'password']);

        $resourceId = null;
        $tenant->run(function () use (&$resourceId) {
            $resourceId = Resource::query()->create([
                'type' => 'tool', 'code' => 'MIXER-01', 'name' => 'Mixer 01', 'is_active' => true,
            ])->id;
        });

        $this->post('/pp/resource-groups', [
            'code' => 'MIXING',
            'name' => 'Mixing Group',
            'members' => [
                ['resource_type' => 'pp_resource', 'resource_ref_id' => $resourceId],
                ['resource_type' => 'mes_work_center', 'resource_ref_id' => 999], // informational — MES not built
            ],
        ])->assertRedirect('/pp/resource-groups');

        $groupId = null;
        $tenant->run(function () use (&$groupId, $resourceId) {
            $group = ResourceGroup::query()->where('code', 'MIXING')->first();
            $this->assertNotNull($group);
            $this->assertSame(2, $group->members()->count());
            $this->assertTrue($group->members()->where('resource_type', 'pp_resource')->where('resource_ref_id', $resourceId)->exists());
            $groupId = $group->id;
        });

        // An invalid pp_resource reference is rejected.
        $this->put("/pp/resource-groups/{$groupId}", [
            'code' => 'MIXING',
            'name' => 'Mixing Group',
            'members' => [
                ['resource_type' => 'pp_resource', 'resource_ref_id' => 999999],
            ],
        ])->assertSessionHasErrors('members.0.resource_ref_id');

        // A valid update replaces the members list (sync, not append).
        $this->put("/pp/resource-groups/{$groupId}", [
            'code' => 'MIXING',
            'name' => 'Mixing Group',
            'members' => [
                ['resource_type' => 'pp_resource', 'resource_ref_id' => $resourceId],
            ],
        ])->assertRedirect('/pp/resource-groups');

        $tenant->run(function () use ($groupId) {
            $this->assertSame(1, ResourceGroupMember::query()->where('resource_group_id', $groupId)->count());
        });

        $this->get("/pp/resource-groups/{$groupId}/edit")->assertOk()->assertInertia(fn ($page) => $page->component('PP/ResourceGroups/Edit'));

        $this->delete("/pp/resource-groups/{$groupId}")->assertRedirect('/pp/resource-groups');
        $tenant->run(function () use ($groupId) {
            $this->assertNull(ResourceGroup::query()->find($groupId));
        });
    }
}
