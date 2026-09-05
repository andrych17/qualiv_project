<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmployeePositionHistory;
use App\Modules\HCM\Models\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3B/§3D — Employee Master minimal-hire entry point, position-history tracking, contract cascade on termination. */
class EmployeeTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_admin_can_hire_an_employee_with_a_pkwtt_contract_and_view_show_page(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $positionId = null;
        $tenant->run(function () use (&$positionId) {
            $positionId = $this->makePosition()->id;
        });

        $this->get('/hcm/employees')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Employees/Index'));
        $this->get('/hcm/employees/create')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Employees/Create'));

        $response = $this->post('/hcm/employees', [
            'full_name' => 'Budi Santoso',
            'hire_date' => '2024-01-10',
            'position_id' => $positionId,
            'contract_type' => EmploymentContract::TYPE_PKWTT,
            'base_salary' => 8000000,
            'probation_end_date' => '2024-04-10',
        ]);

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employeeId = Employee::query()->where('full_name', 'Budi Santoso')->value('id');
        });
        $response->assertRedirect(route('hcm.employees.show', $employeeId));

        $tenant->run(function () use ($employeeId) {
            $employee = Employee::query()->find($employeeId);
            $this->assertNotEmpty($employee->employee_no);
            $this->assertSame(Employee::STATUS_ACTIVE, $employee->employment_status);
            $this->assertSame(1, EmployeePositionHistory::query()->where('employee_id', $employeeId)->count());

            $contract = EmploymentContract::query()->where('employee_id', $employeeId)->first();
            $this->assertSame(EmploymentContract::TYPE_PKWTT, $contract->contract_type);
            $this->assertNull($contract->end_date);
            $this->assertNotNull($contract->probation_end_date);
        });

        $this->get("/hcm/employees/{$employeeId}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('HCM/Employees/Show')->where('employee.full_name', 'Budi Santoso'));
    }

    public function test_admin_can_hire_an_employee_with_a_pkwt_contract_and_without_position(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $this->post('/hcm/employees', [
            'full_name' => 'Siti Aminah',
            'hire_date' => '2024-02-01',
            'contract_type' => EmploymentContract::TYPE_PKWT,
            'contract_end_date' => '2024-12-01',
            'base_salary' => 6000000,
        ])->assertSessionDoesntHaveErrors();

        $tenant->run(function () {
            $employee = Employee::query()->where('full_name', 'Siti Aminah')->first();
            $this->assertNull($employee->position_id);
            $this->assertSame(0, EmployeePositionHistory::query()->where('employee_id', $employee->id)->count());

            $contract = EmploymentContract::query()->where('employee_id', $employee->id)->first();
            $this->assertSame(EmploymentContract::TYPE_PKWT, $contract->contract_type);
            $this->assertNotNull($contract->end_date);
            $this->assertNull($contract->probation_end_date);
        });
    }

    public function test_hire_rejects_duplicate_employee_no_and_invalid_position(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $this->makeEmployee(['employee_no' => 'EMP-9999']);
        });

        $this->post('/hcm/employees', [
            'employee_no' => 'EMP-9999',
            'full_name' => 'Duplicate Person',
            'hire_date' => '2024-01-01',
        ])->assertSessionHasErrors(['employee_no']);

        $this->post('/hcm/employees', [
            'full_name' => 'Bad Position',
            'hire_date' => '2024-01-01',
            'position_id' => 999999,
        ])->assertSessionHasErrors(['position_id']);
    }

    public function test_admin_can_update_employee_and_position_change_is_tracked_in_history(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $positionAId, $positionBId] = [null, null, null];
        $tenant->run(function () use (&$employeeId, &$positionAId, &$positionBId) {
            $positionAId = $this->makePosition()->id;
            $positionBId = $this->makePosition($this->makeJob('JOB-B', 'Job B'), $this->makeOrgUnit('Unit B'))->id;
            $employee = $this->makeEmployee(['position_id' => $positionAId]);
            EmployeePositionHistory::query()->create([
                'employee_id' => $employee->id,
                'position_id' => $positionAId,
                'effective_from' => $employee->hire_date,
            ]);
            $employeeId = $employee->id;
        });

        $this->get("/hcm/employees/{$employeeId}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->component('HCM/Employees/Edit'));

        $this->put("/hcm/employees/{$employeeId}", [
            'employee_no' => 'EMP-0001',
            'full_name' => 'Updated Name',
            'hire_date' => '2023-01-01',
            'employment_status' => Employee::STATUS_ACTIVE,
            'position_id' => $positionBId,
        ])->assertRedirect(route('hcm.employees.show', $employeeId));

        $tenant->run(function () use ($employeeId, $positionBId) {
            $employee = Employee::query()->find($employeeId);
            $this->assertSame('Updated Name', $employee->full_name);
            $this->assertSame($positionBId, $employee->position_id);

            $this->assertSame(2, EmployeePositionHistory::query()->where('employee_id', $employeeId)->count());
            $this->assertSame(
                1,
                EmployeePositionHistory::query()->where('employee_id', $employeeId)->whereNull('effective_to')->count()
            );
        });
    }

    public function test_update_rejects_duplicate_employee_no_from_another_employee(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeAId, $employeeBId] = [null, null];
        $tenant->run(function () use (&$employeeAId, &$employeeBId) {
            $employeeAId = $this->makeEmployee(['employee_no' => 'EMP-AAAA'])->id;
            $employeeBId = $this->makeEmployee(['employee_no' => 'EMP-BBBB'])->id;
        });

        $this->put("/hcm/employees/{$employeeBId}", [
            'employee_no' => 'EMP-AAAA',
            'full_name' => 'Employee B',
            'hire_date' => '2024-01-01',
            'employment_status' => Employee::STATUS_ACTIVE,
        ])->assertSessionHasErrors(['employee_no']);
    }

    public function test_update_rejects_invalid_position_id(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employeeId = $this->makeEmployee()->id;
        });

        $this->put("/hcm/employees/{$employeeId}", [
            'employee_no' => 'EMP-0001',
            'full_name' => 'Employee One',
            'hire_date' => '2024-01-01',
            'employment_status' => Employee::STATUS_ACTIVE,
            'position_id' => 999999,
        ])->assertSessionHasErrors(['position_id']);
    }

    public function test_admin_can_terminate_an_employee_which_cascades_to_active_contract(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employee = $this->makeEmployee();
            $this->makeContract($employee);
            $employeeId = $employee->id;
        });

        $this->post("/hcm/employees/{$employeeId}/terminate", [
            'termination_date' => '2024-06-01',
            'termination_reason' => 'Resigned',
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $employee = Employee::query()->find($employeeId);
            $this->assertSame(Employee::STATUS_TERMINATED, $employee->employment_status);
            $this->assertSame('Resigned', $employee->termination_reason);

            $contract = EmploymentContract::query()->where('employee_id', $employeeId)->first();
            $this->assertSame(EmploymentContract::STATUS_TERMINATED, $contract->status);
        });
    }

    public function test_admin_can_delete_and_bulk_delete_employees(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employeeId = $this->makeEmployee()->id;
        });

        $this->delete("/hcm/employees/{$employeeId}")->assertRedirect(route('hcm.employees.index'));
        $tenant->run(function () use ($employeeId) {
            $this->assertNull(Employee::query()->find($employeeId));
        });

        $ids = [];
        $tenant->run(function () use (&$ids) {
            $ids[] = $this->makeEmployee()->id;
            $ids[] = $this->makeEmployee()->id;
        });
        $this->delete('/hcm/employees/bulk-destroy', ['ids' => $ids])->assertRedirect();
        $tenant->run(function () use ($ids) {
            $this->assertSame(0, Employee::query()->whereIn('id', $ids)->count());
        });
    }

    public function test_employee_index_filters_by_search_status_position_and_org_unit(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$positionId, $orgUnitId] = [null, null];
        $tenant->run(function () use (&$positionId, &$orgUnitId) {
            $orgUnit = $this->makeOrgUnit('Marketing');
            $position = $this->makePosition($this->makeJob('MKT-1', 'Marketer'), $orgUnit);
            $orgUnitId = $orgUnit->id;
            $positionId = $position->id;

            $this->makeEmployee(['full_name' => 'Findable Person', 'employee_no' => 'EMP-FIND', 'position_id' => $positionId]);
            $this->makeEmployee(['full_name' => 'Suspended Person', 'employment_status' => Employee::STATUS_SUSPENDED]);
        });

        $this->get('/hcm/employees?search=Findable')->assertOk()
            ->assertInertia(fn ($page) => $page->has('employees.data', 1));

        $this->get('/hcm/employees?employment_status='.Employee::STATUS_SUSPENDED)->assertOk()
            ->assertInertia(fn ($page) => $page->has('employees.data', 1));

        $this->get("/hcm/employees?position_id={$positionId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('employees.data', 1));

        $this->get("/hcm/employees?org_unit_id={$orgUnitId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('employees.data', 1));

        $this->get('/hcm/employees?sort=full_name&direction=asc&per_page=5')->assertOk();
    }
}
