<?php

use App\Modules\MES\Controllers\BatchExecutionController;
use App\Modules\MES\Controllers\MachineController;
use App\Modules\MES\Controllers\MaterialConsumptionController;
use App\Modules\MES\Controllers\MesAuditLogController;
use App\Modules\MES\Controllers\ProcessPhaseController;
use App\Modules\MES\Controllers\ProdEventController;
use App\Modules\MES\Controllers\ProdOrderController;
use App\Modules\MES\Controllers\ProductionOutputController;
use App\Modules\MES\Controllers\QcHoldController;
use App\Modules\MES\Controllers\QcPlanController;
use App\Modules\MES\Controllers\QcSampleController;
use App\Modules\MES\Controllers\ReworkController;
use App\Modules\MES\Controllers\RoutingController;
use App\Modules\MES\Controllers\ShopFloorController;
use App\Modules\MES\Controllers\StationController;
use App\Modules\MES\Controllers\TraceabilityController;
use App\Modules\MES\Controllers\WorkCenterController;
use Illuminate\Support\Facades\Route;

// §3D lands on Work Centers first — the top of the equipment hierarchy this module builds
// outward from (Plant → Area/Line → Work Center → Machine → Station).
Route::redirect('/mes', '/mes/work-centers');

Route::middleware(['auth', 'verified', 'module:MES', 'menu.perm:MES'])
    ->prefix('mes')
    ->name('mes.')
    ->group(function () {
        // §3D Equipment Master Data — Work Centers / Machines / Stations.
        Route::delete('work-centers/bulk-destroy', [WorkCenterController::class, 'bulkDestroy'])->name('workCenters.bulkDestroy');
        Route::resource('work-centers', WorkCenterController::class)->except(['show'])->names('workCenters');

        Route::delete('machines/bulk-destroy', [MachineController::class, 'bulkDestroy'])->name('machines.bulkDestroy');
        Route::resource('machines', MachineController::class)->except(['show']);

        Route::delete('stations/bulk-destroy', [StationController::class, 'bulkDestroy'])->name('stations.bulkDestroy');
        Route::resource('stations', StationController::class)->except(['show']);

        // §3E Routing / Operations (discrete) — header + ops, one active version per product.
        Route::delete('routings/bulk-destroy', [RoutingController::class, 'bulkDestroy'])->name('routings.bulkDestroy');
        Route::resource('routings', RoutingController::class)->except(['show']);

        // §3F Process Phases & Parameters (process) — one recipe's whole phase set at a time;
        // {recipe} binds against PP.pp_recipes.id, since MES owns no header row here.
        Route::get('process-phases', [ProcessPhaseController::class, 'index'])->name('processPhases.index');
        Route::get('process-phases/create', [ProcessPhaseController::class, 'create'])->name('processPhases.create');
        Route::post('process-phases', [ProcessPhaseController::class, 'store'])->name('processPhases.store');
        Route::get('process-phases/{recipe}/edit', [ProcessPhaseController::class, 'edit'])->name('processPhases.edit');
        Route::put('process-phases/{recipe}', [ProcessPhaseController::class, 'update'])->name('processPhases.update');
        Route::delete('process-phases/{recipe}', [ProcessPhaseController::class, 'destroy'])->name('processPhases.destroy');

        // §3A Production Order — one header for both production models. `release`/`cancel` are
        // this controller's own lifecycle actions; `in_progress`/`paused`/`completed` are driven
        // by the Shop Floor execution engines below (§3G/§3I).
        Route::post('prod-orders/{prodOrder}/release', [ProdOrderController::class, 'release'])->name('prodOrders.release');
        Route::post('prod-orders/{prodOrder}/cancel', [ProdOrderController::class, 'cancel'])->name('prodOrders.cancel');
        Route::resource('prod-orders', ProdOrderController::class)->names('prodOrders');

        // §3J Material Consumption & Production Output — single write action off a Production
        // Order; no index/edit, a row is a posted stock movement, immutable once made.
        Route::post('prod-orders/{prodOrder}/material-consumptions', [MaterialConsumptionController::class, 'store'])->name('prodOrders.materialConsumptions.store');
        Route::post('prod-orders/{prodOrder}/production-outputs', [ProductionOutputController::class, 'store'])->name('prodOrders.productionOutputs.store');

        // §3N Scrap & Rework — "Send to Rework" turns a waste/rework output row into a child
        // Production Order (ReworkService). Scrap recording itself is just a production-output
        // row (§3J) with output_type=waste — already covered, no separate route for it.
        Route::post('production-outputs/{productionOutput}/rework', [ReworkController::class, 'store'])->name('productionOutputs.rework');

        // §3C Production Event Ledger — read-only, system-written only (see ProdEventController).
        Route::get('prod-events', [ProdEventController::class, 'index'])->name('prodEvents.index');

        // §3G Shop Floor Operation UI (assembly) — dedicated layout, not the admin chrome above.
        // §3H Serial Genealogy links write themselves inside ShopFloorController::complete().
        Route::get('shop-floor/{prodOrder}', [ShopFloorController::class, 'show'])->name('shopFloor.show');
        Route::post('shop-floor/{prodOrder}/start', [ShopFloorController::class, 'start'])->name('shopFloor.start');
        Route::post('shop-floor/{prodOrder}/pause', [ShopFloorController::class, 'pause'])->name('shopFloor.pause');
        Route::post('shop-floor/{prodOrder}/resume', [ShopFloorController::class, 'resume'])->name('shopFloor.resume');
        Route::post('shop-floor/{prodOrder}/complete', [ShopFloorController::class, 'complete'])->name('shopFloor.complete');

        // §3I Batch / Phase UI (process) — same dedicated-layout posture as §3G. MVP: one batch
        // drives an order (`mes_batch_relations` split/merge is schema-only, no UI, §4 note).
        Route::get('shop-floor/{prodOrder}/batch', [BatchExecutionController::class, 'show'])->name('shopFloor.batch.show');
        Route::post('shop-floor/{prodOrder}/batch', [BatchExecutionController::class, 'store'])->name('shopFloor.batch.store');
        Route::post('shop-floor/{prodOrder}/batch/start', [BatchExecutionController::class, 'start'])->name('shopFloor.batch.start');
        Route::post('shop-floor/{prodOrder}/batch/pause', [BatchExecutionController::class, 'pause'])->name('shopFloor.batch.pause');
        Route::post('shop-floor/{prodOrder}/batch/resume', [BatchExecutionController::class, 'resume'])->name('shopFloor.batch.resume');
        Route::post('shop-floor/{prodOrder}/batch/complete-phase', [BatchExecutionController::class, 'completePhase'])->name('shopFloor.batch.completePhase');

        // §3L Quality Control — inspection plan master data (Phase 1 basic) + sample recording
        // + hold release. Record-only holds (see mes_qc_holds migration's own note).
        Route::resource('qc-plans', QcPlanController::class)->except(['show'])->names('qcPlans');
        Route::post('qc-samples', [QcSampleController::class, 'store'])->name('qcSamples.store');
        Route::post('qc-holds/{qcHold}/release', [QcHoldController::class, 'release'])->name('qcHolds.release');

        // §3K Traceability & Genealogy — read-only, no dedicated table (derived over §3H/§3I/§3J).
        Route::get('traceability', [TraceabilityController::class, 'index'])->name('traceability.index');

        // §3U Digital Audit Trail — read-only, system-written only (see MesAuditLogger).
        Route::get('audit-logs', [MesAuditLogController::class, 'index'])->name('auditLogs.index');
    });
