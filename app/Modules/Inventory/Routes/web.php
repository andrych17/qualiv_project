<?php

use App\Modules\Inventory\Controllers\InventoryItemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:INVENTORY', 'menu.perm:INVENTORY'])
    ->prefix('inventory')
    ->name('inventory.')
    ->group(function () {
        Route::resource('items', InventoryItemController::class);
    });
