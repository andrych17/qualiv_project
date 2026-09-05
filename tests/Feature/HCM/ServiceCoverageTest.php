<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\HCM\Services\AttendanceService;
use App\Modules\HCM\Services\ContractService;
use App\Modules\HCM\Services\EmployeeService;
use App\Modules\HCM\Services\LeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Direct service-layer coverage for two categories of code the controllers never reach over HTTP:
 * (1) paginate()/updateType() methods the controllers bypass in favor of building their own inline
 *     queries — dead code, kept here rather than deleted since removing it wasn't asked for; and
 * (2) defensive checks (PKWT end-date, leave end<start) already blocked earlier by the FormRequest
 *     validation rules, so the service-level guard is unreachable via any HTTP route.
 */
class ServiceCoverageTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_leave_service_update_type_and_paginate_requests(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $service = app(LeaveService::class);
            $leaveType = $this->makeLeaveType();

            $updated = $service->updateType($leaveType, ['name' => 'Renamed Type']);
            $this->assertSame('Renamed Type', $updated->fresh()->name);

            $page = $service->paginateRequests([], 10);
            $this->assertSame(0, $page->total());
        });
    }

    public function test_leave_service_submit_request_rejects_end_before_start_at_service_layer(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $service = app(LeaveService::class);
            $employee = $this->makeEmployee();
            $leaveType = $this->makeLeaveType();

            $this->expectException(ValidationException::class);
            $service->submitRequest($employee->id, [
                'leave_type_id' => $leaveType->id,
                'start_date' => now()->addDays(5)->toDateString(),
                'end_date' => now()->addDays(4)->toDateString(),
            ]);
        });
    }

    public function test_contract_service_paginate(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $service = app(ContractService::class);
            $this->makeContract($this->makeEmployee());

            $page = $service->paginate([], 10);
            $this->assertSame(1, $page->total());
        });
    }

    public function test_contract_service_rejects_missing_end_date_for_pkwt_at_service_layer(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $service = app(ContractService::class);
            $employee = $this->makeEmployee();

            $this->expectException(ValidationException::class);
            $service->create([
                'employee_id' => $employee->id,
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => now()->toDateString(),
                'base_salary' => 5000000,
            ]);
        });
    }

    public function test_employee_service_paginate(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $service = app(EmployeeService::class);
            $this->makeEmployee();

            $page = $service->paginate([], 10);
            $this->assertSame(1, $page->total());
        });
    }

    public function test_attendance_service_paginate_logs(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $service = app(AttendanceService::class);
            $page = $service->paginateLogs([], 10);
            $this->assertSame(0, $page->total());
        });
    }
}
