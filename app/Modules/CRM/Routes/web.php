<?php

use App\Modules\CRM\Controllers\CompanyController;
use App\Modules\CRM\Controllers\ContactController;
use App\Modules\CRM\Controllers\CrmDashboardController;
use App\Modules\CRM\Controllers\LeadActivityController;
use App\Modules\CRM\Controllers\LeadController;
use App\Modules\CRM\Controllers\PartnerMergeController;
use App\Modules\CRM\Controllers\ServiceCaseActivityController;
use App\Modules\CRM\Controllers\ServiceCaseController;
use App\Modules\CRM\Controllers\TicketController;
use App\Modules\CRM\Controllers\TicketMessageController;
use Illuminate\Support\Facades\Route;

// §3A: CRM's own dashboard is now the section landing page — ships last per
// CRM_SPECS.md's build order since it aggregates §3B-§3G. Keep this redirect,
// SysConfigSeeder's CRM menu_link, and AppBreadcrumb.vue's SECTION_HOME in sync —
// three places carry the same "where does /crm land" decision.
Route::redirect('/crm', '/crm/dashboard');

Route::middleware(['auth', 'verified', 'module:CRM', 'menu.perm:CRM'])
    ->prefix('crm')
    ->name('crm.')
    ->group(function () {
        Route::get('dashboard', [CrmDashboardController::class, 'index'])->name('dashboard');
        Route::get('dashboard/lead/{lead}', [CrmDashboardController::class, 'leadDrawer'])->name('dashboard.lead');
        Route::get('dashboard/ticket/{ticket}', [CrmDashboardController::class, 'ticketDrawer'])->name('dashboard.ticket');
        Route::get('dashboard/case/{case}', [CrmDashboardController::class, 'caseDrawer'])->name('dashboard.case');
        Route::get('dashboard/partner/{partner}', [CrmDashboardController::class, 'partnerDrawer'])->name('dashboard.partner');

        Route::delete('contacts/bulk-destroy', [ContactController::class, 'bulkDestroy'])->name('contacts.bulkDestroy');
        Route::resource('contacts', ContactController::class)->except(['show']);

        Route::delete('companies/bulk-destroy', [CompanyController::class, 'bulkDestroy'])->name('companies.bulkDestroy');
        Route::resource('companies', CompanyController::class)->except(['show']);

        Route::patch('leads/{lead}/stage', [LeadController::class, 'updateStage'])->name('leads.updateStage');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
        Route::post('leads/{lead}/disqualify', [LeadController::class, 'disqualify'])->name('leads.disqualify');
        Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->name('leads.activities.store');
        Route::resource('leads', LeadController::class)->except(['show', 'destroy']);

        Route::patch('service-cases/{serviceCase}/status', [ServiceCaseController::class, 'updateStatus'])->name('serviceCases.updateStatus');
        Route::post('service-cases/{serviceCase}/activities', [ServiceCaseActivityController::class, 'store'])->name('serviceCases.activities.store');
        Route::resource('service-cases', ServiceCaseController::class)
            ->except(['show', 'destroy'])
            ->names('serviceCases')
            ->parameters(['service-cases' => 'serviceCase']);

        Route::patch('tickets/{ticket}/status', [TicketController::class, 'updateStatus'])->name('tickets.updateStatus');
        Route::post('tickets/{ticket}/relink', [TicketController::class, 'relink'])->name('tickets.relink');
        Route::post('tickets/{ticket}/messages', [TicketMessageController::class, 'store'])->name('tickets.messages.store');
        Route::resource('tickets', TicketController::class)->except(['show', 'destroy']);
    });

// §3G: admin-only (see menu.perm:CRM_MERGE — STAFF/VIEWER get no rights on this code,
// unlike the shared 'CRM' code above), but still plan-gated under module:CRM since
// Merge is meaningless without CRM itself entitled.
Route::middleware(['auth', 'verified', 'module:CRM', 'menu.perm:CRM_MERGE'])
    ->prefix('crm/merge')
    ->name('crm.merge.')
    ->group(function () {
        Route::get('/', [PartnerMergeController::class, 'index'])->name('index');
        Route::post('/', [PartnerMergeController::class, 'store'])->name('store');
    });
