<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Accounting\Events\PayrollRunPaid;
use App\Modules\Accounting\Models\Company;
use App\Modules\HCM\Models\Employee;
use App\Modules\Payroll\Models\EmployeeLoan;
use App\Modules\Payroll\Models\EmployeeRecurringDeduction;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayrollRunService
{
    public function __construct(
        protected Pph21TerCalculator $taxCalculator,
        protected BpjsCalculator $bpjsCalculator,
        protected ThrCalculator $thrCalculator,
        protected SeveranceCalculator $severanceCalculator,
    ) {}

    public function createDraftRun(array $attributes): PayrollRun
    {
        $runNumber = $attributes['run_number'] ?? 'PAY-'.date('Ym').'-'.strtoupper(Str::random(4));

        return PayrollRun::query()->create([
            'run_number' => $runNumber,
            'payroll_group_id' => $attributes['payroll_group_id'] ?? null,
            'period_start' => $attributes['period_start'],
            'period_end' => $attributes['period_end'],
            'pay_date' => $attributes['pay_date'] ?? $attributes['period_end'],
            'run_type' => $attributes['run_type'] ?? PayrollRun::TYPE_REGULAR,
            'status' => PayrollRun::STATUS_DRAFT,
        ]);
    }

    public function calculateRun(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run->update(['status' => PayrollRun::STATUS_CALCULATING]);

            // Clean existing lines
            $run->lines()->delete();

            // Query target employees
            $employeeQuery = Employee::query()
                ->where('employment_status', Employee::STATUS_ACTIVE)
                ->with(['currentContract', 'payrollProfile']);

            if ($run->payroll_group_id) {
                $employeeQuery->whereHas('payrollProfile', function ($q) use ($run) {
                    $q->where('payroll_group_id', $run->payroll_group_id);
                });
            }

            $employees = $employeeQuery->get();

            $totalGross = 0;
            $totalDeductions = 0;
            $totalNet = 0;
            $totalTaxPph21 = 0;
            $totalBpjsEmployer = 0;
            $totalBpjsEmployee = 0;

            foreach ($employees as $emp) {
                $profile = $emp->payrollProfile;
                $contract = $emp->currentContract;

                $basicSalary = (float) ($contract?->base_salary ?? 0);
                $ptkpCode = $profile?->ptkp_status_code ?? 'TK/0';
                $hasNpwp = $profile?->has_npwp ?? (! empty($emp->npwp));

                // 1. Calculate BPJS
                $jkkRiskRate = 0.0024;
                if ($profile && $profile->jkkRiskCategory) {
                    $jkkRiskRate = (float) $profile->jkkRiskCategory->employer_rate;
                }
                $bpjs = $this->bpjsCalculator->calculate($basicSalary, $jkkRiskRate);

                // 2. Gross Salary & PPh 21 Calculation
                if ($run->run_type === PayrollRun::TYPE_THR) {
                    $thrResult = $this->thrCalculator->calculate($basicSalary, $emp->hire_date, $run->period_end);
                    $grossTotal = $thrResult['thr_amount'];
                    $taxResult = $this->taxCalculator->calculate($grossTotal, $ptkpCode, $hasNpwp);
                    $otherDeductions = 0.0;
                    $bpjsKesEmployer = 0.0;
                    $bpjsKesEmployee = 0.0;
                    $bpjsTkEmployer = 0.0;
                    $bpjsTkEmployee = 0.0;
                } elseif ($run->run_type === PayrollRun::TYPE_SEVERANCE) {
                    $sevResult = $this->severanceCalculator->calculate(
                        $basicSalary,
                        $emp->hire_date,
                        $emp->termination_date ?? $run->period_end,
                        $emp->termination_reason ?? 'resignation'
                    );
                    $grossTotal = $sevResult['total_package'];
                    $taxResult = $this->taxCalculator->calculate($grossTotal, $ptkpCode, $hasNpwp);
                    $otherDeductions = 0.0;
                    $bpjsKesEmployer = 0.0;
                    $bpjsKesEmployee = 0.0;
                    $bpjsTkEmployer = 0.0;
                    $bpjsTkEmployee = 0.0;
                } else {
                    // Regular payroll
                    $bpjsKesEmployer = $bpjs['kes_employer'];
                    $bpjsKesEmployee = $bpjs['kes_employee'];
                    $bpjsTkEmployer = $bpjs['jht_employer'] + $bpjs['jp_employer'] + $bpjs['jkk_employer'] + $bpjs['jkm_employer'];
                    $bpjsTkEmployee = $bpjs['jht_employee'] + $bpjs['jp_employee'];

                    // Taxable gross includes Basic + Employer-paid JKK, JKM, BPJS Kes
                    $taxableGross = $basicSalary + $bpjs['jkk_employer'] + $bpjs['jkm_employer'] + $bpjs['kes_employer'];
                    $grossTotal = $basicSalary;

                    $taxResult = $this->taxCalculator->calculate($taxableGross, $ptkpCode, $hasNpwp);

                    // Loans and recurring deductions
                    $loanInstallments = (float) EmployeeLoan::query()
                        ->where('employee_id', $emp->id)
                        ->where('status', 'active')
                        ->sum('monthly_installment');

                    $periodYm = Carbon::parse($run->period_start)->format('Y-m');
                    $recurringDeds = (float) EmployeeRecurringDeduction::query()
                        ->where('employee_id', $emp->id)
                        ->where('status', 'active')
                        ->where('start_period', '<=', $periodYm)
                        ->where(fn ($q) => $q->whereNull('end_period')->orWhere('end_period', '>=', $periodYm))
                        ->sum('amount');

                    $otherDeductions = $loanInstallments + $recurringDeds;
                }

                $taxAmount = $taxResult['tax_amount'];
                $totalEmployeeDeductions = $taxAmount + $bpjsKesEmployee + $bpjsTkEmployee + $otherDeductions;
                $netTotal = max(0, $grossTotal - $totalEmployeeDeductions);
                $takeHomePay = $netTotal;

                // Create Run Line
                $line = PayrollRunLine::query()->create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $emp->id,
                    'basic_salary' => $basicSalary,
                    'taxable_earnings' => $taxableGross,
                    'non_taxable_earnings' => 0,
                    'gross_total' => $grossTotal,
                    'bpjs_kesehatan_employer' => $bpjsKesEmployer,
                    'bpjs_kesehatan_employee' => $bpjsKesEmployee,
                    'bpjs_tk_employer' => $bpjsTkEmployer,
                    'bpjs_tk_employee' => $bpjsTkEmployee,
                    'pph21_amount' => $taxAmount,
                    'other_deductions' => $otherDeductions,
                    'net_total' => $netTotal,
                    'take_home_pay' => $takeHomePay,
                    'ptkp_status_code' => $ptkpCode,
                    'ter_category' => $taxResult['ter_category'],
                    'ter_rate_percentage' => $taxResult['rate_percentage'],
                ]);

                // Record itemized components
                $line->details()->create([
                    'component_name' => 'Basic Salary',
                    'type' => PayrollComponent::TYPE_EARNING,
                    'category' => PayrollComponent::CATEGORY_FIXED,
                    'amount' => $basicSalary,
                ]);

                if ($taxAmount > 0) {
                    $line->details()->create([
                        'component_name' => 'PPh 21 (TER '.$taxResult['ter_category'].')',
                        'type' => PayrollComponent::TYPE_DEDUCTION,
                        'category' => PayrollComponent::CATEGORY_STATUTORY,
                        'amount' => $taxAmount,
                    ]);
                }

                if ($bpjsKesEmployee > 0) {
                    $line->details()->create([
                        'component_name' => 'BPJS Kesehatan (Employee 1%)',
                        'type' => PayrollComponent::TYPE_DEDUCTION,
                        'category' => PayrollComponent::CATEGORY_STATUTORY,
                        'amount' => $bpjsKesEmployee,
                    ]);
                }

                if ($bpjsTkEmployee > 0) {
                    $line->details()->create([
                        'component_name' => 'BPJS Ketenagakerjaan (Employee JHT+JP 3%)',
                        'type' => PayrollComponent::TYPE_DEDUCTION,
                        'category' => PayrollComponent::CATEGORY_STATUTORY,
                        'amount' => $bpjsTkEmployee,
                    ]);
                }

                // Accumulate run header totals
                $totalGross += $grossTotal;
                $totalDeductions += $totalEmployeeDeductions;
                $totalNet += $netTotal;
                $totalTaxPph21 += $taxAmount;
                $totalBpjsEmployer += ($bpjsKesEmployer + $bpjsTkEmployer);
                $totalBpjsEmployee += ($bpjsKesEmployee + $bpjsTkEmployee);
            }

            $run->update([
                'status' => PayrollRun::STATUS_CALCULATED,
                'total_gross' => $totalGross,
                'total_deductions' => $totalDeductions,
                'total_net' => $totalNet,
                'total_tax_pph21' => $totalTaxPph21,
                'total_bpjs_employer' => $totalBpjsEmployer,
                'total_bpjs_employee' => $totalBpjsEmployee,
            ]);

            return $run;
        });
    }

    public function approveRun(PayrollRun $run, int $userId): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_CALCULATED) {
            throw ValidationException::withMessages(['status' => 'Only calculated payroll runs with processed lines can be approved.']);
        }
        if ($run->lines()->count() === 0) {
            throw ValidationException::withMessages(['status' => 'Cannot approve a payroll run with zero lines.']);
        }

        $run->update([
            'status' => PayrollRun::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        return $run;
    }

    public function markPaid(PayrollRun $run): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_APPROVED) {
            throw ValidationException::withMessages(['status' => 'Only approved payroll runs can be marked as paid.']);
        }

        $run->update([
            'status' => PayrollRun::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $run->load(['lines.details', 'payrollGroup']);

        $componentTotals = [];
        foreach ($run->lines as $line) {
            foreach ($line->details as $detail) {
                $code = Str::slug($detail->component_name, '_');
                $componentTotals[$code] = ($componentTotals[$code] ?? 0.0) + (float) $detail->amount;
            }
        }

        $eventLines = [];
        foreach ($componentTotals as $code => $amount) {
            $eventLines[] = [
                'component_code' => $code,
                'amount' => round($amount, 2),
            ];
        }

        $company = Company::query()->first();

        if ($company) {
            event(new PayrollRunPaid(
                companyId: $company->id,
                runDate: $run->pay_date ? $run->pay_date->toDateString() : now()->toDateString(),
                lines: $eventLines,
                subjectType: 'payroll.runs',
                subjectId: (string) $run->id,
                memo: "Payroll run #{$run->run_number}",
            ));
        }

        return $run;
    }

    public function lockRun(PayrollRun $run, int $userId): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_PAID) {
            throw ValidationException::withMessages(['status' => 'Only paid payroll runs can be locked.']);
        }

        $run->update([
            'is_locked' => true,
            'status' => PayrollRun::STATUS_LOCKED,
            'locked_by' => $userId,
            'locked_at' => now(),
        ]);

        return $run;
    }
}
