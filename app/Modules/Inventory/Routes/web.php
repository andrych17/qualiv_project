<?php

use App\Modules\Inventory\Controllers\AdjustmentController;
use App\Modules\Inventory\Controllers\AdjustmentReasonController;
use App\Modules\Inventory\Controllers\BarcodeScanController;
use App\Modules\Inventory\Controllers\BatchController;
use App\Modules\Inventory\Controllers\CycleCountController;
use App\Modules\Inventory\Controllers\DashboardController;
use App\Modules\Inventory\Controllers\GoodsIssueController;
use App\Modules\Inventory\Controllers\GoodsReceiptController;
use App\Modules\Inventory\Controllers\InventoryItemController;
use App\Modules\Inventory\Controllers\InventoryValuationController;
use App\Modules\Inventory\Controllers\LocationController;
use App\Modules\Inventory\Controllers\PackListController;
use App\Modules\Inventory\Controllers\PickListController;
use App\Modules\Inventory\Controllers\ProductCategoryController;
use App\Modules\Inventory\Controllers\ProductController;
use App\Modules\Inventory\Controllers\PutawayRuleController;
use App\Modules\Inventory\Controllers\ReservationController;
use App\Modules\Inventory\Controllers\SerialController;
use App\Modules\Inventory\Controllers\ShipmentController;
use App\Modules\Inventory\Controllers\StockCardController;
use App\Modules\Inventory\Controllers\TransferController;
use App\Modules\Inventory\Controllers\UomController;
use App\Modules\Inventory\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::redirect('/inventory', '/inventory/dashboard');

Route::middleware(['auth', 'verified', 'module:INVENTORY', 'menu.perm:INVENTORY'])
    ->prefix('inventory')
    ->name('inventory.')
    ->group(function () {
        // §3A Main Dashboard — read-only aggregate over every other §3 engine, no tables of its own.
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // §3B Product Master — the real Inventory engine (INVENTORY.* schema).
        Route::delete('products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulkDestroy');
        Route::resource('products', ProductController::class)->except(['show']);
        Route::delete('categories/bulk-destroy', [ProductCategoryController::class, 'bulkDestroy'])->name('categories.bulkDestroy');
        Route::resource('categories', ProductCategoryController::class)->except(['show']);
        Route::delete('uoms/bulk-destroy', [UomController::class, 'bulkDestroy'])->name('uoms.bulkDestroy');
        Route::resource('uoms', UomController::class)->except(['show']);

        // §3C Warehouse & Location Management — locations are a tree nested under a
        // warehouse (explicit routes, not dot-resource, matching Legal's DeedParty pattern).
        Route::delete('warehouses/bulk-destroy', [WarehouseController::class, 'bulkDestroy'])->name('warehouses.bulkDestroy');
        Route::resource('warehouses', WarehouseController::class)->except(['show']);
        Route::get('warehouses/{warehouse}/locations/create', [LocationController::class, 'create'])->name('warehouses.locations.create');
        Route::post('warehouses/{warehouse}/locations', [LocationController::class, 'store'])->name('warehouses.locations.store');
        Route::get('warehouses/{warehouse}/locations/{location}/edit', [LocationController::class, 'edit'])->name('warehouses.locations.edit');
        Route::put('warehouses/{warehouse}/locations/{location}', [LocationController::class, 'update'])->name('warehouses.locations.update');
        Route::delete('warehouses/{warehouse}/locations/{location}', [LocationController::class, 'destroy'])->name('warehouses.locations.destroy');

        // §3D/§3E Goods Receipt/Issue — the working ledger engine. Posting is a dedicated
        // action (not part of update()) since it's the one irreversible step (§3D/§3E:
        // posted documents are immutable, corrected via a reversing Adjustment, §3G later).
        Route::resource('goods-receipts', GoodsReceiptController::class)->except(['show'])->names('goodsReceipts');
        Route::patch('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('goodsReceipts.post');
        Route::resource('goods-issues', GoodsIssueController::class)->except(['show'])->names('goodsIssues');
        Route::patch('goods-issues/{goodsIssue}/post', [GoodsIssueController::class, 'post'])->name('goodsIssues.post');

        // §3F Transfers — a paired issue+receipt written atomically on post(); `complete()`
        // is a separate, ledger-inert confirmation step for cross-warehouse transfers.
        Route::resource('transfers', TransferController::class)->except(['show']);
        Route::patch('transfers/{transfer}/post', [TransferController::class, 'post'])->name('transfers.post');
        Route::patch('transfers/{transfer}/complete', [TransferController::class, 'complete'])->name('transfers.complete');

        // §3G Adjustments — reason codes are a tenant-editable lookup (seeded defaults).
        Route::get('adjustments/balance', [AdjustmentController::class, 'balance'])->name('adjustments.balance');
        Route::resource('adjustments', AdjustmentController::class)->except(['show']);
        Route::patch('adjustments/{adjustment}/post', [AdjustmentController::class, 'post'])->name('adjustments.post');
        Route::delete('adjustment-reasons/bulk-destroy', [AdjustmentReasonController::class, 'bulkDestroy'])->name('adjustmentReasons.bulkDestroy');
        Route::resource('adjustment-reasons', AdjustmentReasonController::class)->except(['show'])->names('adjustmentReasons');

        // §3H Stock Card — read-only report over stock_ledger, no posting.
        Route::get('stock-card', [StockCardController::class, 'index'])->name('stockCard.index');

        // §3I Inventory Valuation — read-only report over stock_valuation_layers (live) /
        // stock_ledger (as-of a past date), no posting.
        Route::get('valuation', [InventoryValuationController::class, 'index'])->name('valuation.index');

        // §3K Barcode Engine — one resolve endpoint shared by every scan input on Receipt/
        // Issue/Transfer lines (product_barcodes) and, later, Picking/Cycle Count (location_barcodes).
        Route::get('barcode-scan', [BarcodeScanController::class, 'resolve'])->name('barcodeScan.resolve');

        // §3L Batch / Lot Tracking — master data + the expiring-soon Status Rail view that
        // stands in for §3A's Dashboard integration, which doesn't exist yet (see BatchController).
        Route::resource('batches', BatchController::class)->except(['show']);

        // §3M Serial Number Tracking — read-only lookup only; rows are created by a Goods
        // Receipt line (SerialService::receive()), never by hand (see SerialController).
        Route::get('serials', [SerialController::class, 'index'])->name('serials.index');

        // §3N Reservations — read-only browse + manual release; rows are created by
        // InventoryService::reserve() (a future caller, not by hand — see ReservationController).
        Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::patch('reservations/{reservation}/release', [ReservationController::class, 'release'])->name('reservations.release');
        Route::post('reservations/generate-pick-list', [ReservationController::class, 'generatePickList'])->name('reservations.generatePickList');

        // §3O Picking — generated from reservations (bulk action on the Reservations page,
        // above), never created by hand. `show` is the mobile-friendly scan-to-pick workspace.
        Route::get('pick-lists', [PickListController::class, 'index'])->name('pickLists.index');
        Route::get('pick-lists/{pickList}', [PickListController::class, 'show'])->name('pickLists.show');
        Route::patch('pick-lists/{pickList}/assign', [PickListController::class, 'assign'])->name('pickLists.assign');
        Route::patch('pick-lists/{pickList}/lines/{line}/pick', [PickListController::class, 'pickLine'])->name('pickLists.pickLine');
        Route::delete('pick-lists/{pickList}', [PickListController::class, 'destroy'])->name('pickLists.destroy');

        // §3P Packing — a package is always started from a pick list's Show page ("Create
        // package"); no bare create route without ?pick_list_id=.
        Route::resource('pack-lists', PackListController::class)->except(['show'])->names('packLists');

        // §3P Shipping — links one or more packed packages; ship-confirm is the one action
        // that triggers the actual Goods Issue (§3E), same "dedicated irreversible action"
        // posture as Goods Receipt/Issue posting above.
        Route::resource('shipments', ShipmentController::class)->except(['show']);
        Route::patch('shipments/{shipment}/ship-confirm', [ShipmentController::class, 'shipConfirm'])->name('shipments.shipConfirm');
        Route::patch('shipments/{shipment}/deliver', [ShipmentController::class, 'markDelivered'])->name('shipments.deliver');

        // §3Q Cycle Counting — scoped scan-to-count workflow; completing with variances drafts
        // one Adjustment (§3G) per counted location for review/approval, never posts
        // automatically ("counting itself never silently changes stock").
        Route::get('cycle-counts', [CycleCountController::class, 'index'])->name('cycleCounts.index');
        Route::get('cycle-counts/create', [CycleCountController::class, 'create'])->name('cycleCounts.create');
        Route::post('cycle-counts', [CycleCountController::class, 'store'])->name('cycleCounts.store');
        Route::get('cycle-counts/{cycleCount}', [CycleCountController::class, 'show'])->name('cycleCounts.show');
        Route::patch('cycle-counts/{cycleCount}/assign', [CycleCountController::class, 'assign'])->name('cycleCounts.assign');
        Route::patch('cycle-counts/{cycleCount}/lines/{line}/count', [CycleCountController::class, 'countLine'])->name('cycleCounts.countLine');
        Route::patch('cycle-counts/{cycleCount}/complete', [CycleCountController::class, 'complete'])->name('cycleCounts.complete');
        Route::delete('cycle-counts/{cycleCount}', [CycleCountController::class, 'destroy'])->name('cycleCounts.destroy');

        // §3R Put-away Rules — tenant-editable lookup; auto-applied as Goods Receipt's line
        // default destination (GoodsReceiptService::syncLines()), always overridable there.
        Route::delete('putaway-rules/bulk-destroy', [PutawayRuleController::class, 'bulkDestroy'])->name('putawayRules.bulkDestroy');
        Route::resource('putaway-rules', PutawayRuleController::class)->except(['show'])->names('putawayRules');

        // Legacy demo tables (public schema) — CLAUDE.md §7A. Not wired to the new
        // INVENTORY.* schema; kept reachable during the transition, not linked from the
        // sidebar (see SysConfigSeeder's INVENTORY menu_link).
        Route::delete('items/bulk-destroy', [InventoryItemController::class, 'bulkDestroy'])->name('items.bulkDestroy');
        Route::resource('items', InventoryItemController::class)->except(['show']);
    });
