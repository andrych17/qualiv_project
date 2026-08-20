<?php

use App\Modules\CRM\Controllers\CompanyController;
use App\Modules\CRM\Controllers\ContactController;
use App\Modules\CRM\Controllers\LeadActivityController;
use App\Modules\CRM\Controllers\LeadController;
use Illuminate\Support\Facades\Route;

Route::redirect('/crm', '/crm/contacts');

Route::middleware(['auth', 'verified', 'module:CRM', 'menu.perm:CRM'])
    ->prefix('crm')
    ->name('crm.')
    ->group(function () {
        Route::delete('contacts/bulk-destroy', [ContactController::class, 'bulkDestroy'])->name('contacts.bulkDestroy');
        Route::resource('contacts', ContactController::class)->except(['show']);

        Route::delete('companies/bulk-destroy', [CompanyController::class, 'bulkDestroy'])->name('companies.bulkDestroy');
        Route::resource('companies', CompanyController::class)->except(['show']);

        Route::patch('leads/{lead}/stage', [LeadController::class, 'updateStage'])->name('leads.updateStage');
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
        Route::post('leads/{lead}/disqualify', [LeadController::class, 'disqualify'])->name('leads.disqualify');
        Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->name('leads.activities.store');
        Route::resource('leads', LeadController::class)->except(['show', 'destroy']);
    });
