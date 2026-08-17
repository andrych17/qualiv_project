<?php

use App\Modules\Central\Controllers\CentralAdminSessionController;
use App\Modules\Central\Controllers\InvoiceController;
use App\Modules\Central\Controllers\PaymentController;
use App\Modules\Central\Controllers\PlanController;
use App\Modules\Central\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

// Platform-admin-only surface (CENTRAL_SPECS.md §3A/§4) — separate `central_admin` guard,
// never reachable from inside a tenant session. No `menu.perm:` middleware here: that's a
// SYSCONFIG (tenant-DB) concept and doesn't apply outside any tenant.
Route::prefix('central')->name('central.')->group(function () {
    Route::get('login', [CentralAdminSessionController::class, 'create'])->name('login');
    Route::post('login', [CentralAdminSessionController::class, 'store']);

    Route::middleware('auth:central_admin')->group(function () {
        Route::post('logout', [CentralAdminSessionController::class, 'destroy'])->name('logout');

        Route::redirect('/', '/central/tenants');

        Route::resource('tenants', TenantController::class)->except(['show', 'destroy']);
        Route::resource('plans', PlanController::class)->except(['show']);

        Route::resource('invoices', InvoiceController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');
    });
});
