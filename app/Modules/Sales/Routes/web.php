<?php

use App\Http\Middleware\InitializeTenancyByRouteParameter;
use App\Modules\Sales\Controllers\CommissionPlanController;
use App\Modules\Sales\Controllers\CommissionSettlementController;
use App\Modules\Sales\Controllers\ContractController;
use App\Modules\Sales\Controllers\CustomerPortalController;
use App\Modules\Sales\Controllers\CustomerProfileController;
use App\Modules\Sales\Controllers\DeliveryController;
use App\Modules\Sales\Controllers\OpportunityController;
use App\Modules\Sales\Controllers\PriceListController;
use App\Modules\Sales\Controllers\PromoCodeController;
use App\Modules\Sales\Controllers\QuotationController;
use App\Modules\Sales\Controllers\ReturnController;
use App\Modules\Sales\Controllers\SalesDashboardController;
use App\Modules\Sales\Controllers\SalesOrderController;
use App\Modules\Sales\Controllers\SalesTeamController;
use App\Modules\Sales\Controllers\TerritoryController;
use Illuminate\Support\Facades\Route;

// Public signed token Customer Portal route (§3D)
Route::get('/portal/{tenant}/sales/{token}', [CustomerPortalController::class, 'show'])
    ->middleware(InitializeTenancyByRouteParameter::class)
    ->name('sales.portal.show');

Route::middleware(['auth', 'module:SALES', 'menu.perm:SALES'])->prefix('sales')->name('sales.')->group(function () {
    // §3A Dashboard
    Route::get('/dashboard', SalesDashboardController::class)->name('dashboard');

    // §3C Opportunities
    Route::get('/opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');
    Route::get('/opportunities/create', [OpportunityController::class, 'create'])->name('opportunities.create');
    Route::post('/opportunities', [OpportunityController::class, 'store'])->name('opportunities.store');
    Route::get('/opportunities/{opportunity}/edit', [OpportunityController::class, 'edit'])->name('opportunities.edit');
    Route::put('/opportunities/{opportunity}', [OpportunityController::class, 'update'])->name('opportunities.update');
    Route::patch('/opportunities/{opportunity}/stage', [OpportunityController::class, 'updateStage'])->name('opportunities.stage');
    Route::delete('/opportunities/{opportunity}', [OpportunityController::class, 'destroy'])->name('opportunities.destroy');

    // §3E Quotations
    Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
    Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
    Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::get('/quotations/{quotation}/edit', [QuotationController::class, 'edit'])->name('quotations.edit');
    Route::put('/quotations/{quotation}', [QuotationController::class, 'update'])->name('quotations.update');
    Route::post('/quotations/{quotation}/send', [QuotationController::class, 'send'])->name('quotations.send');
    Route::post('/quotations/{quotation}/convert', [QuotationController::class, 'convertToOrder'])->name('quotations.convert');
    Route::post('/quotations/{quotation}/clone', [QuotationController::class, 'cloneExpired'])->name('quotations.clone');
    Route::delete('/quotations/{quotation}', [QuotationController::class, 'destroy'])->name('quotations.destroy');

    // §3F Sales Orders
    Route::get('/orders', [SalesOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [SalesOrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [SalesOrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [SalesOrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/edit', [SalesOrderController::class, 'edit'])->name('orders.edit');
    Route::put('/orders/{order}', [SalesOrderController::class, 'update'])->name('orders.update');
    Route::post('/orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->name('orders.confirm');
    Route::post('/orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->name('orders.cancel');
    Route::post('/orders/{order}/invoice', [SalesOrderController::class, 'requestInvoice'])->name('orders.invoice');
    Route::delete('/orders/{order}', [SalesOrderController::class, 'destroy'])->name('orders.destroy');

    // §3H Deliveries
    Route::get('/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::get('/deliveries/create', [DeliveryController::class, 'create'])->name('deliveries.create');
    Route::post('/deliveries', [DeliveryController::class, 'store'])->name('deliveries.store');
    Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
    Route::patch('/deliveries/{delivery}/status', [DeliveryController::class, 'updateStatus'])->name('deliveries.status');

    // §3L Contracts & Subscriptions
    Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
    Route::get('/contracts/create', [ContractController::class, 'create'])->name('contracts.create');
    Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
    Route::get('/contracts/{contract}/edit', [ContractController::class, 'edit'])->name('contracts.edit');
    Route::put('/contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
    Route::post('/contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
    Route::post('/contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
    Route::post('/contracts/{contract}/renew', [ContractController::class, 'renew'])->name('contracts.renew');
    Route::post('/contracts/recurring/process', [ContractController::class, 'triggerRecurringBilling'])->name('contracts.recurring.process');

    // §3J Returns
    Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('/returns/create', [ReturnController::class, 'create'])->name('returns.create');
    Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
    Route::get('/returns/{return}', [ReturnController::class, 'show'])->name('returns.show');
    Route::post('/returns/{return}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
    Route::post('/returns/{return}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
    Route::post('/returns/{return}/refund', [ReturnController::class, 'refund'])->name('returns.refund');
    Route::post('/returns/{return}/replace', [ReturnController::class, 'replace'])->name('returns.replace');

    // §3M Commissions
    Route::get('/commissions', [CommissionSettlementController::class, 'index'])->name('commissions.index');
    Route::post('/commissions', [CommissionSettlementController::class, 'store'])->name('commissions.store');
    Route::get('/commissions/{settlement}', [CommissionSettlementController::class, 'show'])->name('commissions.show');
    Route::post('/commissions/{settlement}/approve', [CommissionSettlementController::class, 'approve'])->name('commissions.approve');
    Route::post('/commissions/{settlement}/pay', [CommissionSettlementController::class, 'markPaid'])->name('commissions.pay');

    // §3B Master configurations
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('/price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
        Route::get('/price-lists/create', [PriceListController::class, 'create'])->name('price-lists.create');
        Route::post('/price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
        Route::get('/price-lists/{priceList}/edit', [PriceListController::class, 'edit'])->name('price-lists.edit');
        Route::put('/price-lists/{priceList}', [PriceListController::class, 'update'])->name('price-lists.update');
        Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy'])->name('price-lists.destroy');

        Route::get('/teams', [SalesTeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [SalesTeamController::class, 'store'])->name('teams.store');
        Route::put('/teams/{team}', [SalesTeamController::class, 'update'])->name('teams.update');
        Route::delete('/teams/{team}', [SalesTeamController::class, 'destroy'])->name('teams.destroy');

        Route::get('/territories', [TerritoryController::class, 'index'])->name('territories.index');
        Route::post('/territories', [TerritoryController::class, 'store'])->name('territories.store');
        Route::put('/territories/{territory}', [TerritoryController::class, 'update'])->name('territories.update');
        Route::delete('/territories/{territory}', [TerritoryController::class, 'destroy'])->name('territories.destroy');

        Route::get('/promo-codes', [PromoCodeController::class, 'index'])->name('promo-codes.index');
        Route::post('/promo-codes', [PromoCodeController::class, 'store'])->name('promo-codes.store');
        Route::put('/promo-codes/{promoCode}', [PromoCodeController::class, 'update'])->name('promo-codes.update');
        Route::delete('/promo-codes/{promoCode}', [PromoCodeController::class, 'destroy'])->name('promo-codes.destroy');

        Route::get('/commission-plans', [CommissionPlanController::class, 'index'])->name('commission-plans.index');
        Route::post('/commission-plans', [CommissionPlanController::class, 'store'])->name('commission-plans.store');
        Route::put('/commission-plans/{commissionPlan}', [CommissionPlanController::class, 'update'])->name('commission-plans.update');
        Route::delete('/commission-plans/{commissionPlan}', [CommissionPlanController::class, 'destroy'])->name('commission-plans.destroy');

        Route::get('/customers', [CustomerProfileController::class, 'index'])->name('customers.index');
        Route::get('/customers/{partner}/edit', [CustomerProfileController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{partner}', [CustomerProfileController::class, 'update'])->name('customers.update');
        Route::post('/customers/{partner}/portal-token', [CustomerProfileController::class, 'generatePortalToken'])->name('customers.portal-token');
    });
});
