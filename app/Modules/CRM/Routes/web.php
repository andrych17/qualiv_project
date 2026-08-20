<?php

use App\Modules\CRM\Controllers\CompanyController;
use App\Modules\CRM\Controllers\ContactController;
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
    });
