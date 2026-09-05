<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\Job;
use App\Modules\HCM\Models\OrgUnit;
use App\Modules\HCM\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3C — Org Units, Jobs (designations catalog), and Positions (org-chart seats): CRUD, filters, and cross-reference validation. */
class OrgStructureTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_admin_can_crud_an_org_unit_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $this->get('/hcm/org-units')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/OrgUnits/Index'));

        $this->post('/hcm/org-units', ['name' => 'Engineering', 'unit_type' => OrgUnit::TYPE_DEPARTMENT])
            ->assertRedirect();

        $orgUnitId = null;
        $tenant->run(function () use (&$orgUnitId) {
            $orgUnitId = OrgUnit::query()->where('name', 'Engineering')->value('id');
        });

        $this->put("/hcm/org-units/{$orgUnitId}", ['name' => 'Engineering (renamed)', 'unit_type' => OrgUnit::TYPE_DIVISION])
            ->assertRedirect();

        $tenant->run(function () use ($orgUnitId) {
            $unit = OrgUnit::query()->find($orgUnitId);
            $this->assertSame('Engineering (renamed)', $unit->name);
            $this->assertSame(OrgUnit::TYPE_DIVISION, $unit->unit_type);
        });

        $childId = null;
        $tenant->run(function () use (&$childId, $orgUnitId) {
            $childId = $this->makeOrgUnit('Backend Team', OrgUnit::TYPE_DEPARTMENT, $orgUnitId)->id;
        });

        $this->put("/hcm/org-units/{$childId}", ['name' => 'Backend Team', 'unit_type' => OrgUnit::TYPE_DEPARTMENT, 'parent_org_unit_id' => $orgUnitId])
            ->assertRedirect();

        $this->delete("/hcm/org-units/{$childId}")->assertRedirect();
        $tenant->run(function () use ($childId) {
            $this->assertNull(OrgUnit::query()->find($childId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeOrgUnit('Bulk A')->id;
            $ids[] = $this->makeOrgUnit('Bulk B')->id;
        });
        $this->delete('/hcm/org-units/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, OrgUnit::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_org_unit_index_filters_by_search_type_and_active(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $this->makeOrgUnit('Finance', OrgUnit::TYPE_DEPARTMENT);
            $this->makeOrgUnit('APAC Branch', OrgUnit::TYPE_BRANCH);
            OrgUnit::query()->create(['name' => 'Legacy Unit', 'unit_type' => OrgUnit::TYPE_DEPARTMENT, 'is_active' => false]);
        });

        $this->get('/hcm/org-units?search=Finance')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orgUnits.data', 1));

        $this->get('/hcm/org-units?unit_type='.OrgUnit::TYPE_BRANCH)->assertOk()
            ->assertInertia(fn ($page) => $page->has('orgUnits.data', 1));

        $this->get('/hcm/org-units?is_active=0')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orgUnits.data', 1));
    }

    public function test_org_unit_store_rejects_invalid_parent(): void
    {
        $this->loginAsHcmAdmin();

        $this->post('/hcm/org-units', [
            'name' => 'Orphan',
            'unit_type' => OrgUnit::TYPE_DEPARTMENT,
            'parent_org_unit_id' => 999999,
        ])->assertSessionHasErrors(['parent_org_unit_id']);
    }

    public function test_admin_can_crud_a_job_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $this->get('/hcm/jobs')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Jobs/Index'));

        $this->post('/hcm/jobs', ['code' => 'BE-DEV', 'title' => 'Backend Developer'])->assertRedirect();

        $jobId = null;
        $tenant->run(function () use (&$jobId) {
            $jobId = Job::query()->where('code', 'BE-DEV')->value('id');
        });

        $this->put("/hcm/jobs/{$jobId}", ['code' => 'BE-DEV', 'title' => 'Senior Backend Developer'])->assertRedirect();
        $tenant->run(function () use ($jobId) {
            $this->assertSame('Senior Backend Developer', Job::query()->find($jobId)->title);
        });

        $this->delete("/hcm/jobs/{$jobId}")->assertRedirect();
        $tenant->run(function () use ($jobId) {
            $this->assertNull(Job::query()->find($jobId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeJob('BULK-1', 'Bulk One')->id;
            $ids[] = $this->makeJob('BULK-2', 'Bulk Two')->id;
        });
        $this->delete('/hcm/jobs/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Job::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_job_index_filters_by_search_and_active(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $this->makeJob('QA-1', 'QA Engineer');
            Job::query()->create(['code' => 'OLD-1', 'title' => 'Retired Role', 'is_active' => false]);
        });

        $this->get('/hcm/jobs?search=QA')->assertOk()->assertInertia(fn ($page) => $page->has('jobs.data', 1));
        $this->get('/hcm/jobs?is_active=0')->assertOk()->assertInertia(fn ($page) => $page->has('jobs.data', 1));
    }

    public function test_job_store_rejects_duplicate_code_and_update_ignores_self(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $jobId = null;
        $tenant->run(function () use (&$jobId) {
            $jobId = $this->makeJob('DUP-CODE', 'Existing Job')->id;
        });

        $this->post('/hcm/jobs', ['code' => 'DUP-CODE', 'title' => 'Another Job'])
            ->assertSessionHasErrors(['code']);

        // Updating the same job with its own code must not trip the unique rule.
        $this->put("/hcm/jobs/{$jobId}", ['code' => 'DUP-CODE', 'title' => 'Existing Job Renamed'])
            ->assertSessionDoesntHaveErrors(['code']);
    }

    public function test_admin_can_crud_a_position_and_bulk_destroy(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$jobId, $orgUnitId] = [null, null];
        $tenant->run(function () use (&$jobId, &$orgUnitId) {
            $jobId = $this->makeJob()->id;
            $orgUnitId = $this->makeOrgUnit()->id;
        });

        $this->get('/hcm/positions')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Positions/Index'));

        $this->post('/hcm/positions', ['job_id' => $jobId, 'org_unit_id' => $orgUnitId, 'headcount_cap' => 3])
            ->assertRedirect();

        $positionId = null;
        $tenant->run(function () use (&$positionId, $jobId) {
            $positionId = Position::query()->where('job_id', $jobId)->value('id');
        });

        $managerId = null;
        $tenant->run(function () use (&$managerId, $jobId, $orgUnitId) {
            $managerId = $this->makePosition($jobId ? Job::find($jobId) : null, OrgUnit::find($orgUnitId))->id;
        });

        $this->put("/hcm/positions/{$positionId}", [
            'job_id' => $jobId,
            'org_unit_id' => $orgUnitId,
            'reports_to_position_id' => $managerId,
            'headcount_cap' => 5,
        ])->assertRedirect();

        $tenant->run(function () use ($positionId, $managerId) {
            $position = Position::query()->find($positionId);
            $this->assertSame($managerId, $position->reports_to_position_id);
            $this->assertSame(5, $position->headcount_cap);
            $this->assertSame($managerId, $position->reportsTo->id);
        });

        $this->delete("/hcm/positions/{$positionId}")->assertRedirect();
        $tenant->run(function () use ($positionId) {
            $this->assertNull(Position::query()->find($positionId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makePosition()->id;
            $ids[] = $this->makePosition()->id;
        });
        $this->delete('/hcm/positions/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Position::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_position_index_filters_by_search_org_unit_and_active(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$orgUnitAId, $orgUnitBId] = [null, null];
        $tenant->run(function () use (&$orgUnitAId, &$orgUnitBId) {
            $orgUnitAId = $this->makeOrgUnit('Sales')->id;
            $orgUnitBId = $this->makeOrgUnit('Support')->id;
            $this->makePosition($this->makeJob('SALES-1', 'Salesperson'), OrgUnit::find($orgUnitAId));
            $inactive = $this->makePosition($this->makeJob('SUP-1', 'Support Agent'), OrgUnit::find($orgUnitBId));
            $inactive->update(['is_active' => false]);
        });

        $this->get('/hcm/positions?search=Salesperson')->assertOk()
            ->assertInertia(fn ($page) => $page->has('positions.data', 1));

        $this->get("/hcm/positions?org_unit_id={$orgUnitAId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('positions.data', 1));

        $this->get('/hcm/positions?is_active=0')->assertOk()
            ->assertInertia(fn ($page) => $page->has('positions.data', 1));
    }

    public function test_position_store_rejects_invalid_job_org_unit_and_reports_to(): void
    {
        $this->loginAsHcmAdmin();

        $this->post('/hcm/positions', [
            'job_id' => 999999,
            'org_unit_id' => 999999,
            'reports_to_position_id' => 999999,
        ])->assertSessionHasErrors(['job_id', 'org_unit_id', 'reports_to_position_id']);
    }
}
