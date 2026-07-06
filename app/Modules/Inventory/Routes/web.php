<?php
// ponytail: Modular routing file loaded dynamically
use App\Modules\Inventory\Controllers\InventoryItemController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('inventory')->name('inventory.')->group(function () {
    Route::resource('items', InventoryItemController::class);
});
