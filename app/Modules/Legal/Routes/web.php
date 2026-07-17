<?php

use App\Modules\Legal\Controllers\LegalCaseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:LEGAL', 'menu.perm:LEGAL'])
    ->prefix('legal')
    ->name('legal.')
    ->group(function () {
        Route::resource('cases', LegalCaseController::class)
            ->except(['show'])
            ->parameters(['cases' => 'case']);
    });
