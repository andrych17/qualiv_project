<?php

use App\Modules\Purchase\Controllers\AnalyticsController;
use App\Modules\Purchase\Controllers\CatalogController;
use App\Modules\Purchase\Controllers\ContractController;
use App\Modules\Purchase\Controllers\DashboardController;
use App\Modules\Purchase\Controllers\ExceptionController;
use App\Modules\Purchase\Controllers\GoodsReceiptController;
use App\Modules\Purchase\Controllers\InvoiceController;
use App\Modules\Purchase\Controllers\PurchaseOrderController;
use App\Modules\Purchase\Controllers\RequisitionController;
use App\Modules\Purchase\Controllers\SourcingController;
use App\Modules\Purchase\Controllers\VendorProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/purchase', '/purchase/dashboard');

Route::middleware(['auth', 'verified', 'module:PURCHASE', 'menu.perm:PURCHASE'])
    ->prefix('purchase')
    ->name('purchase.')
    ->group(function () {
        // §3A Procurement Dashboard
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // §3C Sourcing / RFx Engine
        Route::post('sourcing/{sourcing}/send', [SourcingController::class, 'send'])->name('sourcing.send');
        Route::post('sourcing/{sourcing}/response', [SourcingController::class, 'recordResponse'])->name('sourcing.response');
        Route::post('sourcing/{sourcing}/award', [SourcingController::class, 'award'])->name('sourcing.award');
        Route::post('sourcing/{sourcing}/cancel', [SourcingController::class, 'cancel'])->name('sourcing.cancel');
        Route::resource('sourcing', SourcingController::class)->except(['edit', 'update', 'destroy']);

        // §3I Catalog Management
        Route::post('catalog/{catalog}/toggle', [CatalogController::class, 'toggle'])->name('catalog.toggle');
        Route::resource('catalog', CatalogController::class)->except(['show', 'destroy']);

        // §3H Contract Management
        Route::post('contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
        Route::post('contracts/{contract}/terminate', [ContractController::class, 'terminate'])->name('contracts.terminate');
        Route::post('contracts/{contract}/renew', [ContractController::class, 'renew'])->name('contracts.renew');
        Route::resource('contracts', ContractController::class);

        // §3G Vendor Profiles
        Route::post('vendors/{vendor}/documents', [VendorProfileController::class, 'storeDocument'])->name('vendors.documents.store');
        Route::resource('vendors', VendorProfileController::class)->except(['show', 'destroy']);

        // §3B Purchase Requisitions (PR)
        Route::post('requisitions/{requisition}/submit', [RequisitionController::class, 'submit'])->name('requisitions.submit');
        Route::post('requisitions/{requisition}/approve', [RequisitionController::class, 'approve'])->name('requisitions.approve');
        Route::post('requisitions/{requisition}/reject', [RequisitionController::class, 'reject'])->name('requisitions.reject');
        Route::post('requisitions/{requisition}/cancel', [RequisitionController::class, 'cancel'])->name('requisitions.cancel');
        Route::post('requisitions/{requisition}/convert-to-po', [RequisitionController::class, 'convertToPo'])->name('requisitions.convert-to-po');
        Route::resource('requisitions', RequisitionController::class);

        // §3D Purchase Orders (PO)
        Route::post('orders/{order}/submit', [PurchaseOrderController::class, 'submit'])->name('orders.submit');
        Route::post('orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->name('orders.approve');
        Route::post('orders/{order}/reject', [PurchaseOrderController::class, 'reject'])->name('orders.reject');
        Route::post('orders/{order}/send', [PurchaseOrderController::class, 'send'])->name('orders.send');
        Route::post('orders/{order}/acknowledge', [PurchaseOrderController::class, 'acknowledge'])->name('orders.acknowledge');
        Route::post('orders/{order}/close', [PurchaseOrderController::class, 'close'])->name('orders.close');
        Route::post('orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('orders.cancel');
        Route::resource('orders', PurchaseOrderController::class);

        // §3E Goods Receipts (GR)
        Route::resource('receipts', GoodsReceiptController::class)->only(['index', 'create', 'store', 'show']);

        // §3F Invoices & Three-Way Match
        Route::post('invoices/{invoice}/rematch', [InvoiceController::class, 'rematch'])->name('invoices.rematch');
        Route::post('invoices/{invoice}/submit', [InvoiceController::class, 'submit'])->name('invoices.submit');
        Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])->name('invoices.approve');
        Route::post('invoices/{invoice}/reject', [InvoiceController::class, 'reject'])->name('invoices.reject');
        Route::resource('invoices', InvoiceController::class)->only(['index', 'create', 'store', 'show']);

        // §3J Spend Analytics & §3M ESG Tracking
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
        Route::get('analytics/spend', [AnalyticsController::class, 'spend'])->name('analytics.spend');
        Route::get('analytics/esg', [AnalyticsController::class, 'esg'])->name('analytics.esg');

        // §3K Exception Management Engine
        Route::post('exceptions/scan', [ExceptionController::class, 'scan'])->name('exceptions.scan');
        Route::post('exceptions/{exception}/resolve', [ExceptionController::class, 'resolve'])->name('exceptions.resolve');
        Route::post('exceptions/{exception}/dismiss', [ExceptionController::class, 'dismiss'])->name('exceptions.dismiss');
        Route::get('exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');
    });
