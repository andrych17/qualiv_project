<?php

use App\Modules\Legal\Controllers\LegalCaseController;
use Illuminate\Support\Facades\Route;

// Bare /legal → index (avoids Laravel 404 if bookmark/link drops /cases)
Route::redirect('/legal', '/legal/cases');

Route::middleware(['auth', 'verified', 'module:LEGAL', 'menu.perm:LEGAL'])
    ->prefix('legal')
    ->name('legal.')
    ->group(function () {
        Route::resource('cases', LegalCaseController::class)
            ->except(['show'])
            ->parameters(['cases' => 'legalCase']);
    });
