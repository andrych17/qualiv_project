<?php

use App\Http\Controllers\Api\AsyncSearchController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DesignSystemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TenantSwitchController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/tenant/switch', TenantSwitchController::class)->name('tenant.switch');

    // CENTRAL_SPECS.md §3H — cross-boundary connection to the central DB, not a REST/API
    // surface. Deliberately not behind `module:` gating — billing must stay reachable
    // regardless of a tenant's own entitlement or access status.
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/invoices/{invoice}/payments', [BillingController::class, 'submitPayment'])->name('billing.payments.store');

    Route::get('/design-system', DesignSystemController::class)
        ->middleware('menu.perm:DESIGN_SYSTEM')
        ->name('design-system');

    Route::get('/api/search', [AsyncSearchController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.search');
});

require __DIR__.'/auth.php';

// Load module routes
require app_path('Modules/Inventory/Routes/web.php');
require app_path('Modules/SysConfig/Routes/web.php');
require app_path('Modules/Legal/Routes/web.php');
require app_path('Modules/CRM/Routes/web.php');
require app_path('Modules/WNE/Routes/web.php');
require app_path('Modules/Schedule/Routes/web.php');
require app_path('Modules/DMS/Routes/web.php');
require app_path('Modules/Accounting/Routes/web.php');
require app_path('Modules/HCM/Routes/web.php');
require app_path('Modules/Payroll/Routes/web.php');
require app_path('Modules/Projects/Routes/web.php');
require app_path('Modules/Central/Routes/web.php');
require app_path('Modules/Sales/Routes/web.php');
require app_path('Modules/Purchase/Routes/web.php');
require app_path('Modules/Performance/Routes/web.php');
require app_path('Modules/PP/Routes/web.php');
