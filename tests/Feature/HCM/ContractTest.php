<?php

namespace Tests\Feature\HCM;

use App\Modules\HCM\Models\EmploymentContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpHCM;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/** §3D — Employment Contracts: Indonesian labor-law compliance (PP 35/2021 — PKWT end-date/no-probation/5-year cap), renewal chain, expiring-contracts queue. */
class ContractTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpHCM;
    use SetsUpTenant;

    public function test_admin_can_create_a_pkwtt_contract(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employeeId = $this->makeEmployee()->id;
        });

        $this->get('/hcm/contracts')->assertOk()->assertInertia(fn ($page) => $page->component('HCM/Contracts/Index'));

        $this->post('/hcm/contracts', [
            'employee_id' => $employeeId,
            'contract_type' => EmploymentContract::TYPE_PKWTT,
            'start_date' => '2024-01-01',
            'base_salary' => 7000000,
            'probation_end_date' => '2024-04-01',
        ])->assertRedirect();

        $tenant->run(function () use ($employeeId) {
            $this->assertSame(1, EmploymentContract::query()->where('employee_id', $employeeId)->count());
        });
    }

    public function test_pkwt_contract_requires_end_date_and_cannot_have_probation(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employeeId = $this->makeEmployee()->id;
        });

        $this->post('/hcm/contracts', [
            'employee_id' => $employeeId,
            'contract_type' => EmploymentContract::TYPE_PKWT,
            'start_date' => '2024-01-01',
            'base_salary' => 5000000,
        ])->assertSessionHasErrors(['end_date']);

        $this->post('/hcm/contracts', [
            'employee_id' => $employeeId,
            'contract_type' => EmploymentContract::TYPE_PKWT,
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-01',
            'base_salary' => 5000000,
            'probation_end_date' => '2024-02-01',
        ])->assertSessionHasErrors(['probation_end_date']);
    }

    public function test_store_rejects_invalid_employee_id(): void
    {
        $this->loginAsHcmAdmin();

        $this->post('/hcm/contracts', [
            'employee_id' => 999999,
            'contract_type' => EmploymentContract::TYPE_PKWTT,
            'start_date' => '2024-01-01',
            'base_salary' => 5000000,
        ])->assertSessionHasErrors(['employee_id']);
    }

    public function test_cumulative_pkwt_duration_over_five_years_via_renewal_chain_is_rejected(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $contractId] = [null, null];
        $tenant->run(function () use (&$employeeId, &$contractId) {
            $employee = $this->makeEmployee();
            $employeeId = $employee->id;
            $contractId = $this->makeContract($employee, [
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => '2020-01-01',
                'end_date' => '2024-06-01',
            ])->id;
        });

        // Renewing this PKWT for another 2 years pushes the cumulative chain past the 5-year (1826 day) cap.
        $this->post("/hcm/contracts/{$contractId}/renew", [
            'contract_type' => EmploymentContract::TYPE_PKWT,
            'start_date' => '2024-06-02',
            'end_date' => '2026-12-01',
            'base_salary' => 5500000,
        ])->assertSessionHasErrors(['end_date']);
    }

    public function test_admin_can_renew_a_contract_within_compliance(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        [$employeeId, $contractId] = [null, null];
        $tenant->run(function () use (&$employeeId, &$contractId) {
            $employee = $this->makeEmployee();
            $employeeId = $employee->id;
            $contractId = $this->makeContract($employee, [
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => '2024-01-01',
                'end_date' => '2024-06-01',
            ])->id;
        });

        $this->post("/hcm/contracts/{$contractId}/renew", [
            'contract_type' => EmploymentContract::TYPE_PKWT,
            'start_date' => '2024-06-02',
            'end_date' => '2024-12-01',
            'base_salary' => 5500000,
        ])->assertRedirect();

        $tenant->run(function () use ($contractId, $employeeId) {
            $old = EmploymentContract::query()->find($contractId);
            $this->assertSame(EmploymentContract::STATUS_RENEWED, $old->status);

            $new = EmploymentContract::query()->where('renewed_from_contract_id', $contractId)->first();
            $this->assertNotNull($new);
            $this->assertSame(EmploymentContract::STATUS_ACTIVE, $new->status);
            $this->assertSame($employeeId, $new->employee_id);
        });
    }

    public function test_admin_can_terminate_a_contract(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $contractId = null;
        $tenant->run(function () use (&$contractId) {
            $contractId = $this->makeContract($this->makeEmployee())->id;
        });

        $this->post("/hcm/contracts/{$contractId}/terminate")->assertRedirect();

        $tenant->run(function () use ($contractId) {
            $this->assertSame(EmploymentContract::STATUS_TERMINATED, EmploymentContract::query()->find($contractId)->status);
        });
    }

    public function test_contract_index_filters_and_lists_expiring_contracts(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $tenant->run(function () {
            $employee = $this->makeEmployee(['full_name' => 'Expiring Soon']);
            $this->makeContract($employee, [
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => now()->subMonths(6)->toDateString(),
                'end_date' => now()->addDays(30)->toDateString(),
            ]);

            $farFuture = $this->makeEmployee();
            $this->makeContract($farFuture, [
                'contract_type' => EmploymentContract::TYPE_PKWT,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYears(2)->toDateString(),
            ]);
        });

        $this->get('/hcm/contracts?search=Expiring Soon')->assertOk()
            ->assertInertia(fn ($page) => $page->has('contracts.data', 1)->has('expiringContracts', 1));

        $this->get('/hcm/contracts?contract_type='.EmploymentContract::TYPE_PKWT)->assertOk();
        $this->get('/hcm/contracts?status='.EmploymentContract::STATUS_ACTIVE)->assertOk();
        $this->get('/hcm/contracts?sort=base_salary&direction=desc')->assertOk();
    }

    public function test_contract_index_filters_by_employee_id(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $employee = $this->makeEmployee();
            $employeeId = $employee->id;
            $this->makeContract($employee);
        });

        $this->get("/hcm/contracts?employee_id={$employeeId}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('contracts.data', 1));
    }

    public function test_renewing_a_pkwtt_contract_into_pkwt_breaks_the_ancestor_chain_walk(): void
    {
        $tenant = $this->loginAsHcmAdmin();

        $contractId = null;
        $tenant->run(function () use (&$contractId) {
            $contractId = $this->makeContract($this->makeEmployee(), [
                'contract_type' => EmploymentContract::TYPE_PKWTT,
                'start_date' => '2018-01-01',
            ])->id;
        });

        // The ancestor is PKWTT, so validateCompliance's chain-walk must stop (break) on the first
        // non-PKWT ancestor instead of adding its duration to the new PKWT's cumulative total.
        $this->post("/hcm/contracts/{$contractId}/renew", [
            'contract_type' => EmploymentContract::TYPE_PKWT,
            'start_date' => '2024-01-01',
            'end_date' => '2024-06-01',
            'base_salary' => 5000000,
        ])->assertRedirect();

        $tenant->run(function () use ($contractId) {
            $this->assertNotNull(EmploymentContract::query()->where('renewed_from_contract_id', $contractId)->first());
        });
    }
}
