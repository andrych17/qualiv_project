<?php

use App\Modules\HCM\Controllers\AttendanceController;
use App\Modules\HCM\Controllers\ContractController;
use App\Modules\HCM\Controllers\DashboardController;
use App\Modules\HCM\Controllers\EmployeeController;
use App\Modules\HCM\Controllers\LeaveController;
use App\Modules\HCM\Controllers\OrgUnitController;
use App\Modules\HCM\Controllers\PositionController;
use App\Modules\HCM\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

Route::redirect('/hcm', '/hcm/dashboard');

Route::middleware(['auth', 'verified', 'module:HCM', 'menu.perm:HCM'])
    ->prefix('hcm')
    ->name('hcm.')
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Employees
        Route::delete('employees/bulk-destroy', [EmployeeController::class, 'bulkDestroy'])->name('employees.bulkDestroy');
        Route::post('employees/{employee}/terminate', [EmployeeController::class, 'terminate'])->name('employees.terminate');
        Route::resource('employees', EmployeeController::class)->names('employees');

        // Org Structure
        Route::delete('org-units/bulk-destroy', [OrgUnitController::class, 'bulkDestroy'])->name('orgUnits.bulkDestroy');
        Route::resource('org-units', OrgUnitController::class)->except(['create', 'show', 'edit'])->names('orgUnits');

        Route::delete('positions/bulk-destroy', [PositionController::class, 'bulkDestroy'])->name('positions.bulkDestroy');
        Route::resource('positions', PositionController::class)->except(['create', 'show', 'edit'])->names('positions');

        // Contracts
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::post('contracts', [ContractController::class, 'store'])->name('contracts.store');
        Route::post('contracts/{contract}/renew', [ContractController::class, 'renew'])->name('contracts.renew');
        Route::post('contracts/{contract}/terminate', [ContractController::class, 'terminate'])->name('contracts.terminate');

        // Attendance & Shifts
        Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
        Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn'])->name('attendance.clockIn');
        Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut'])->name('attendance.clockOut');
        Route::post('attendance/corrections', [AttendanceController::class, 'storeCorrection'])->name('attendance.corrections.store');
        Route::patch('attendance/corrections/{correction}/review', [AttendanceController::class, 'reviewCorrection'])->name('attendance.corrections.review');
        Route::post('attendance/assign-shift', [AttendanceController::class, 'assignShift'])->name('attendance.assignShift');

        Route::delete('shifts/bulk-destroy', [ShiftController::class, 'bulkDestroy'])->name('shifts.bulkDestroy');
        Route::resource('shifts', ShiftController::class)->except(['create', 'show', 'edit'])->names('shifts');

        // Leave Management
        Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
        Route::post('leave/requests', [LeaveController::class, 'store'])->name('leave.requests.store');
        Route::patch('leave/requests/{leaveRequest}/review', [LeaveController::class, 'review'])->name('leave.requests.review');
        Route::post('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->name('leave.requests.cancel');
        Route::post('leave/types', [LeaveController::class, 'storeType'])->name('leave.types.store');
        Route::post('leave/types/{leaveType}/policy', [LeaveController::class, 'setPolicy'])->name('leave.types.setPolicy');
    });
