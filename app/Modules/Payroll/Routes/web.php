<?php

use App\Modules\Payroll\Controllers\DashboardController;
use App\Modules\Payroll\Controllers\EmployeePayrollProfileController;
use App\Modules\Payroll\Controllers\PayrollComponentController;
use App\Modules\Payroll\Controllers\PayrollRunController;
use App\Modules\Payroll\Controllers\PayslipController;
use App\Modules\Payroll\Controllers\ReimbursementController;
use App\Modules\Payroll\Controllers\SalaryStructureController;
use Illuminate\Support\Facades\Route;

Route::redirect('/payroll', '/payroll/dashboard');

Route::middleware(['auth', 'verified', 'module:PAYROLL', 'menu.perm:PAYROLL'])
    ->prefix('payroll')
    ->name('payroll.')
    ->group(function () {
        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Payroll Runs
        Route::get('/runs', [PayrollRunController::class, 'index'])->name('runs.index');
        Route::get('/runs/create', [PayrollRunController::class, 'create'])->name('runs.create');
        Route::post('/runs', [PayrollRunController::class, 'store'])->name('runs.store');
        Route::get('/runs/{run}', [PayrollRunController::class, 'show'])->name('runs.show');
        Route::post('/runs/{run}/calculate', [PayrollRunController::class, 'calculate'])->name('runs.calculate');
        Route::post('/runs/{run}/approve', [PayrollRunController::class, 'approve'])->name('runs.approve');
        Route::post('/runs/{run}/mark-paid', [PayrollRunController::class, 'markPaid'])->name('runs.markPaid');
        Route::post('/runs/{run}/lock', [PayrollRunController::class, 'lock'])->name('runs.lock');

        // Payslips
        Route::get('/payslips', [PayslipController::class, 'index'])->name('payslips.index');
        Route::get('/payslips/{line}', [PayslipController::class, 'show'])->name('payslips.show');

        // Employee Payroll Profiles
        Route::get('/profiles', [EmployeePayrollProfileController::class, 'index'])->name('profiles.index');
        Route::put('/profiles/{employee}', [EmployeePayrollProfileController::class, 'update'])->name('profiles.update');

        // Payroll Components
        Route::get('/components', [PayrollComponentController::class, 'index'])->name('components.index');
        Route::post('/components', [PayrollComponentController::class, 'store'])->name('components.store');
        Route::put('/components/{component}', [PayrollComponentController::class, 'update'])->name('components.update');

        // Salary Structures
        Route::get('/structures', [SalaryStructureController::class, 'index'])->name('structures.index');
        Route::post('/structures', [SalaryStructureController::class, 'store'])->name('structures.store');
        Route::post('/structures/{structure}/components', [SalaryStructureController::class, 'attachComponent'])->name('structures.attachComponent');

        // Reimbursements
        Route::get('/reimbursements', [ReimbursementController::class, 'index'])->name('reimbursements.index');
        Route::post('/reimbursements', [ReimbursementController::class, 'store'])->name('reimbursements.store');
        Route::patch('/reimbursements/{claim}/review', [ReimbursementController::class, 'review'])->name('reimbursements.review');
    });
