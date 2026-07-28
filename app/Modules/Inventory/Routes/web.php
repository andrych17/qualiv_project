<?php

use App\Modules\Inventory\Controllers\InventoryItemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:INVENTORY', 'menu.perm:INVENTORY'])
    ->prefix('inventory')
    ->name('inventory.')
    ->group(function () {
        Route::delete('items/bulk-destroy', [InventoryItemController::class, 'bulkDestroy'])->name('items.bulkDestroy');
        Route::resource('items', InventoryItemController::class);
    });
