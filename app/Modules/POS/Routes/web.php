<?php

use App\Modules\POS\Controllers\PosFloorController;
use App\Modules\POS\Controllers\PosKdsController;
use App\Modules\POS\Controllers\PosOfflineSyncController;
use App\Modules\POS\Controllers\PosProfileController;
use App\Modules\POS\Controllers\PosReportController;
use App\Modules\POS\Controllers\PosReturnController;
use App\Modules\POS\Controllers\PosSaleController;
use App\Modules\POS\Controllers\PosSessionController;
use App\Modules\POS\Controllers\PosTerminalController;
use Illuminate\Support\Facades\Route;

Route::redirect('/pos', '/pos/sale');

Route::middleware(['auth', 'verified', 'module:POS', 'menu.perm:POS'])
    ->prefix('pos')
    ->name('pos.')
    ->group(function () {
        // §3E, §3F, §3I Cashier Register & Cart
        Route::get('sale', [PosSaleController::class, 'index'])->name('sale.index');
        Route::post('sale/scan', [PosSaleController::class, 'scan'])->name('sale.scan');
        Route::post('sale/draft', [PosSaleController::class, 'createDraft'])->name('sale.draft');
        Route::post('sale/{txn}/lines', [PosSaleController::class, 'addLine'])->name('sale.lines.add');
        Route::delete('sale/{txn}/lines/{lineId}', [PosSaleController::class, 'removeLine'])->name('sale.lines.remove');
        Route::post('sale/{txn}/discount', [PosSaleController::class, 'applyDiscount'])->name('sale.discount');
        Route::post('sale/{txn}/park', [PosSaleController::class, 'park'])->name('sale.park');
        Route::post('sale/{txn}/resume', [PosSaleController::class, 'resume'])->name('sale.resume');
        Route::post('sale/{txn}/void', [PosSaleController::class, 'void'])->name('sale.void');
        Route::post('sale/{txn}/pay', [PosSaleController::class, 'pay'])->name('sale.pay');
        Route::post('sale/{txn}/complete', [PosSaleController::class, 'complete'])->name('sale.complete');

        // §3C, §3D Sessions & Shifts
        Route::get('sessions', [PosSessionController::class, 'index'])->name('sessions.index');
        Route::get('sessions/{session}', [PosSessionController::class, 'show'])->name('sessions.show');
        Route::post('sessions/open', [PosSessionController::class, 'open'])->name('sessions.open');
        Route::post('sessions/{session}/movement', [PosSessionController::class, 'cashMovement'])->name('sessions.movement');
        Route::post('sessions/{session}/close', [PosSessionController::class, 'close'])->name('sessions.close');

        // §3B, §3Q Terminals & Devices
        Route::get('terminals', [PosTerminalController::class, 'index'])->name('terminals.index');
        Route::post('terminals', [PosTerminalController::class, 'store'])->name('terminals.store');
        Route::put('terminals/{terminal}', [PosTerminalController::class, 'update'])->name('terminals.update');
        Route::post('terminals/{terminal}/devices', [PosTerminalController::class, 'addDevice'])->name('terminals.devices.add');

        // §3A Profiles
        Route::get('profiles', [PosProfileController::class, 'index'])->name('profiles.index');
        Route::post('profiles', [PosProfileController::class, 'store'])->name('profiles.store');
        Route::put('profiles/{profile}', [PosProfileController::class, 'update'])->name('profiles.update');

        // §3M Floor & Tables
        Route::get('floors', [PosFloorController::class, 'index'])->name('floors.index');
        Route::post('floors', [PosFloorController::class, 'storeFloor'])->name('floors.store');
        Route::post('tables', [PosFloorController::class, 'storeTable'])->name('tables.store');
        Route::post('tables/open', [PosFloorController::class, 'openTable'])->name('tables.open');
        Route::post('tables/move', [PosFloorController::class, 'moveTable'])->name('tables.move');
        Route::post('tables/merge', [PosFloorController::class, 'mergeTables'])->name('tables.merge');

        // §3O Kitchen Display System (KDS)
        Route::get('kds', [PosKdsController::class, 'index'])->name('kds.index');
        Route::get('kds/queue', [PosKdsController::class, 'queue'])->name('kds.queue');
        Route::post('kds/route/{txn}', [PosKdsController::class, 'routeOrder'])->name('kds.route');
        Route::post('kds/lines/{line}/status', [PosKdsController::class, 'updateStatus'])->name('kds.updateStatus');

        // §3L Returns & Refunds
        Route::get('returns', [PosReturnController::class, 'index'])->name('returns.index');
        Route::post('returns', [PosReturnController::class, 'store'])->name('returns.store');

        // §3U Reports & Analytics
        Route::get('reports', [PosReportController::class, 'index'])->name('reports.index');

        // §3S Offline Sync Queue
        Route::post('sync', [PosOfflineSyncController::class, 'sync'])->name('sync');
    });
