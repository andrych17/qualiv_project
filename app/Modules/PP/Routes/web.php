<?php

use App\Modules\PP\Controllers\BomController;
use App\Modules\PP\Controllers\DemandController;
use App\Modules\PP\Controllers\DemandForecastController;
use App\Modules\PP\Controllers\ItemPlanningParamController;
use App\Modules\PP\Controllers\CapacityPlanController;
use App\Modules\PP\Controllers\MpsController;
use App\Modules\PP\Controllers\PlannedOrderController;
use App\Modules\PP\Controllers\RecipeController;
use App\Modules\PP\Controllers\ResourceController;
use App\Modules\PP\Controllers\ResourceGroupController;
use Illuminate\Support\Facades\Route;

Route::redirect('/pp', '/pp/item-planning-params');

Route::middleware(['auth', 'verified', 'module:PP', 'menu.perm:PP'])
    ->prefix('pp')
    ->name('pp.')
    ->group(function () {
        // §3A Item Planning Parameters — one row per INVENTORY.products item.
        Route::delete('item-planning-params/bulk-destroy', [ItemPlanningParamController::class, 'bulkDestroy'])->name('itemPlanningParams.bulkDestroy');
        Route::resource('item-planning-params', ItemPlanningParamController::class)->except(['show'])->names('itemPlanningParams');

        // §3B Demand Aggregation — read model over every source, plus manual entry.
        Route::post('demand/recalculate-safety-stock', [DemandController::class, 'recalculateSafetyStock'])->name('demand.recalculateSafetyStock');
        Route::resource('demand', DemandController::class)->except(['show']);

        // §3B Demand Forecasts — master data; each row syncs one demand line.
        Route::delete('demand-forecasts/bulk-destroy', [DemandForecastController::class, 'bulkDestroy'])->name('demandForecasts.bulkDestroy');
        Route::resource('demand-forecasts', DemandForecastController::class)->except(['show'])->names('demandForecasts');

        // §3D BOM / Recipe master data — PP's own, not read from MES.
        Route::delete('boms/bulk-destroy', [BomController::class, 'bulkDestroy'])->name('boms.bulkDestroy');
        Route::resource('boms', BomController::class)->except(['show']);
        Route::delete('recipes/bulk-destroy', [RecipeController::class, 'bulkDestroy'])->name('recipes.bulkDestroy');
        Route::resource('recipes', RecipeController::class)->except(['show']);

        // §3D MRP Engine & Planned Orders — system-generated (MrpService::run()); release is
        // the one write action a planner takes on a planned order.
        Route::post('planned-orders/run-mrp', [PlannedOrderController::class, 'runMrp'])->name('plannedOrders.runMrp');
        Route::patch('planned-orders/{plannedOrder}/release', [PlannedOrderController::class, 'release'])->name('plannedOrders.release');
        Route::get('planned-orders', [PlannedOrderController::class, 'index'])->name('plannedOrders.index');

        // §3C Master Production Schedule — the planner-facing grid over §3B/§3D's data, plus
        // firm/release actions on the same underlying planned orders.
        Route::get('mps', [MpsController::class, 'index'])->name('mps.index');
        Route::post('mps', [MpsController::class, 'store'])->name('mps.store');
        Route::delete('mps/{mpsHeader}', [MpsController::class, 'destroy'])->name('mps.destroy');
        Route::patch('mps/lines/{mpsLine}', [MpsController::class, 'updateQty'])->name('mps.lines.update');
        Route::patch('mps/lines/{mpsLine}/freeze', [MpsController::class, 'toggleFreeze'])->name('mps.lines.freeze');
        Route::patch('mps/lines/{mpsLine}/firm', [MpsController::class, 'firm'])->name('mps.lines.firm');
        Route::patch('mps/lines/{mpsLine}/unfirm', [MpsController::class, 'unfirm'])->name('mps.lines.unfirm');
        Route::patch('mps/lines/{mpsLine}/release', [MpsController::class, 'release'])->name('mps.lines.release');

        // §3E Resource & Resource Group Reference — resource types no other Core module owns yet.
        Route::delete('resources/bulk-destroy', [ResourceController::class, 'bulkDestroy'])->name('resources.bulkDestroy');
        Route::resource('resources', ResourceController::class)->except(['show']);
        Route::delete('resource-groups/bulk-destroy', [ResourceGroupController::class, 'bulkDestroy'])->name('resourceGroups.bulkDestroy');
        Route::resource('resource-groups', ResourceGroupController::class)->except(['show'])->names('resourceGroups');

        // §3F Capacity Planning — RCCP; rough-cut/informational only in Phase 1.
        Route::delete('capacity-plans/bulk-destroy', [CapacityPlanController::class, 'bulkDestroy'])->name('capacityPlans.bulkDestroy');
        Route::resource('capacity-plans', CapacityPlanController::class)->except(['show'])->names('capacityPlans');
    });
