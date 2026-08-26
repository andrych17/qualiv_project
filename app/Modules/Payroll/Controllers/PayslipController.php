<?php

namespace App\Modules\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payroll\Models\PayrollRunLine;
use Inertia\Inertia;
use Inertia\Response;

class PayslipController extends Controller
{
    public function show(PayrollRunLine $line): Response
    {
        $line->load([
            'payrollRun.payrollGroup',
            'employee.position.job',
            'employee.position.orgUnit',
            'employee.currentContract',
            'details',
        ]);

        return Inertia::render('Payroll/Payslips/Show', [
            'payslip' => $line,
        ]);
    }
}
