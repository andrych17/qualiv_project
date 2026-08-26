<?php

namespace Tests\Feature;

use App\Modules\HCM\Models\Employee;
use App\Modules\HCM\Models\EmploymentContract;
use App\Modules\Payroll\Models\EmployeePayrollProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunLine;
use App\Modules\Payroll\Models\ReimbursementCategory;
use App\Modules\Payroll\Models\ReimbursementClaim;
use App\Modules\Payroll\Services\BpjsCalculator;
use App\Modules\Payroll\Services\Pph21TerCalculator;
use App\Modules\Payroll\Services\SeveranceCalculator;
use App\Modules\Payroll\Services\ThrCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class PayrollModuleTest extends TestCase
{
    use RefreshDatabase;
    use SetsUpTenant;

    public function test_starter_plan_blocks_payroll_module(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'starter']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/payroll/dashboard')->assertForbidden();
    }

    public function test_admin_can_access_payroll_dashboard(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $this->get('/payroll/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Payroll/Dashboard/Index'));
    }

    public function test_pph21_ter_calculator_logic(): void
    {
        $calc = new Pph21TerCalculator;

        // PTKP TK/0 (Cat A) on Rp 10.000.000 gross -> ~2%
        $resA = $calc->calculate(10000000, 'TK/0', true);
        $this->assertEquals('A', $resA['ter_category']);
        $this->assertGreaterThan(0, $resA['tax_amount']);
        $this->assertFalse($resA['is_non_npwp_penalized']);

        // Non-NPWP penalty (120% surcharge)
        $resNoNpwp = $calc->calculate(10000000, 'TK/0', false);
        $this->assertTrue($resNoNpwp['is_non_npwp_penalized']);
        $this->assertEquals(round($resA['tax_amount'] * 1.20, 2), $resNoNpwp['tax_amount']);

        // PTKP K/3 (Cat C)
        $resC = $calc->calculate(8000000, 'K/3', true);
        $this->assertEquals('C', $resC['ter_category']);
    }

    public function test_bpjs_calculator_caps_and_contributions(): void
    {
        $calc = new BpjsCalculator;

        // Base salary Rp 15.000.000 (exceeds Kes cap 12jt and JP cap 10.042.300)
        $res = $calc->calculate(15000000);

        // Kes Employer: 4% of 12.000.000 = 480.000
        $this->assertEquals(480000.0, $res['kes_employer']);
        // Kes Employee: 1% of 12.000.000 = 120.000
        $this->assertEquals(120000.0, $res['kes_employee']);

        // JHT Employer: 3.7% of 15.000.000 = 555.000
        $this->assertEquals(555000.0, $res['jht_employer']);
        // JHT Employee: 2% of 15.000.000 = 300.000
        $this->assertEquals(300000.0, $res['jht_employee']);

        // JP Employer: 2% of 10.042.300 = 200.846
        $this->assertEquals(200846.0, $res['jp_employer']);
    }

    public function test_thr_and_severance_calculators(): void
    {
        $thrCalc = new ThrCalculator;
        // Full tenure >= 12 months -> 100%
        $thrFull = $thrCalc->calculate(10000000, '2024-01-01', '2025-04-01');
        $this->assertEquals(10000000.0, $thrFull['thr_amount']);
        $this->assertFalse($thrFull['is_prorated']);

        // Prorated tenure (6 months) -> ~50%
        $thrPro = $thrCalc->calculate(12000000, '2025-01-01', '2025-07-01');
        $this->assertTrue($thrPro['is_prorated']);
        $this->assertGreaterThan(5000000, $thrPro['thr_amount']);

        $sevCalc = new SeveranceCalculator;
        // 4 years service redundancy termination
        $sev = $sevCalc->calculate(10000000, '2020-01-01', '2024-01-01', 'redundancy');
        $this->assertEquals(4, $sev['years_of_service']);
        $this->assertEquals(5, $sev['severance_months']); // 5 months
        $this->assertEquals(2, $sev['reward_months']); // 2 months UPMK
        $this->assertEquals(70000000.0, $sev['severance_amount'] + $sev['reward_amount']);
        $this->assertEquals(10500000.0, $sev['compensation_amount']); // 15% UPH
    }

    public function test_payroll_run_calculation_and_approval_flow(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $employeeId = null;
        $tenant->run(function () use (&$employeeId) {
            $emp = Employee::query()->create([
                'employee_no' => 'EMP-5001',
                'full_name' => 'Maya Kartika',
                'hire_date' => '2024-01-01',
                'employment_status' => Employee::STATUS_ACTIVE,
            ]);

            EmploymentContract::query()->create([
                'employee_id' => $emp->id,
                'contract_type' => 'PKWTT',
                'start_date' => '2024-01-01',
                'base_salary' => 10000000,
                'status' => 'active',
            ]);

            EmployeePayrollProfile::query()->create([
                'employee_id' => $emp->id,
                'ptkp_status_code' => 'TK/0',
                'has_npwp' => true,
                'is_active' => true,
            ]);

            $employeeId = $emp->id;
        });

        // 1. Create Draft Run
        $this->post('/payroll/runs', [
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'pay_date' => '2026-03-31',
            'run_type' => 'regular',
        ])->assertRedirect();

        $runId = null;
        $tenant->run(function () use (&$runId) {
            $run = PayrollRun::query()->first();
            $this->assertNotNull($run);
            $this->assertEquals(PayrollRun::STATUS_DRAFT, $run->status);
            $runId = $run->id;
        });

        // 2. Calculate Run
        $this->post("/payroll/runs/{$runId}/calculate")->assertRedirect();

        $lineId = null;
        $tenant->run(function () use ($runId, $employeeId, &$lineId) {
            $run = PayrollRun::query()->find($runId);
            $this->assertEquals(PayrollRun::STATUS_CALCULATED, $run->status);
            $this->assertGreaterThan(0, $run->total_gross);
            $this->assertGreaterThan(0, $run->total_net);

            $line = PayrollRunLine::query()
                ->where('payroll_run_id', $runId)
                ->where('employee_id', $employeeId)
                ->first();

            $this->assertNotNull($line);
            $this->assertEquals(10000000, $line->basic_salary);
            $this->assertGreaterThan(0, $line->pph21_amount);
            $this->assertGreaterThan(0, $line->take_home_pay);
            $lineId = $line->id;
        });

        // 3. View Single Payslip
        $this->get("/payroll/payslips/{$lineId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Payroll/Payslips/Show'));

        // 4. Approve Run
        $this->post("/payroll/runs/{$runId}/approve")->assertRedirect();

        $tenant->run(function () use ($runId) {
            $run = PayrollRun::query()->find($runId);
            $this->assertEquals(PayrollRun::STATUS_APPROVED, $run->status);
            $this->assertNotNull($run->approved_by);
        });

        // 5. Mark Paid
        $this->post("/payroll/runs/{$runId}/mark-paid")->assertRedirect();

        $tenant->run(function () use ($runId) {
            $run = PayrollRun::query()->find($runId);
            $this->assertEquals(PayrollRun::STATUS_PAID, $run->status);
            $this->assertNotNull($run->paid_at);
        });
    }

    public function test_reimbursement_flow(): void
    {
        $tenant = $this->provisionTenant();
        $tenant->update(['plan' => 'full']);

        $this->post('/login', [
            'email' => 'admin@nusaevo.com',
            'password' => 'password',
        ]);

        $employeeId = null;
        $catId = null;

        $tenant->run(function () use (&$employeeId, &$catId) {
            $emp = Employee::query()->create([
                'employee_no' => 'EMP-6001',
                'full_name' => 'Doni Siregar',
                'hire_date' => '2025-01-01',
                'employment_status' => Employee::STATUS_ACTIVE,
            ]);

            $cat = ReimbursementCategory::query()->create([
                'code' => 'MEDICAL',
                'name' => 'Kacamata / Rawat Jalan',
                'max_claim_amount' => 2000000,
                'is_active' => true,
            ]);

            $employeeId = $emp->id;
            $catId = $cat->id;
        });

        // Submit claim
        $this->post('/payroll/reimbursements', [
            'employee_id' => $employeeId,
            'reimbursement_category_id' => $catId,
            'claim_date' => '2026-03-10',
            'amount' => 750000,
            'description' => 'Klaim resep kacamata kerja',
        ])->assertRedirect();

        $claimId = null;
        $tenant->run(function () use (&$claimId, $employeeId) {
            $claim = ReimbursementClaim::query()->where('employee_id', $employeeId)->first();
            $this->assertNotNull($claim);
            $this->assertEquals(ReimbursementClaim::STATUS_PENDING, $claim->status);
            $claimId = $claim->id;
        });

        // Review & approve claim
        $this->patch("/payroll/reimbursements/{$claimId}/review", [
            'status' => 'approved',
        ])->assertRedirect();

        $tenant->run(function () use ($claimId) {
            $claim = ReimbursementClaim::query()->find($claimId);
            $this->assertEquals(ReimbursementClaim::STATUS_APPROVED, $claim->status);
            $this->assertNotNull($claim->reviewed_by);
        });
    }
}
