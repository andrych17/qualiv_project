<?php

use App\Http\Controllers\DashboardController;
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
});

require __DIR__.'/auth.php';

// Load module routes
require app_path('Modules/Inventory/Routes/web.php');
require app_path('Modules/Config/Routes/web.php');
require app_path('Modules/Legal/Routes/web.php');
