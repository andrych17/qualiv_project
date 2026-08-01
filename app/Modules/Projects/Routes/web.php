<?php

use App\Modules\Projects\Controllers\IssueCommentController;
use App\Modules\Projects\Controllers\IssueController;
use App\Modules\Projects\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:PROJECTS', 'menu.perm:PROJECTS'])
    ->group(function () {
        Route::delete('projects/bulk-destroy', [ProjectController::class, 'bulkDestroy'])->name('projects.bulkDestroy');
        Route::resource('projects', ProjectController::class)->names('projects');

        Route::prefix('projects/{project}')->name('projects.')->group(function () {
            Route::post('issues', [IssueController::class, 'store'])->name('issues.store');
            Route::get('issues/{issue}/edit', [IssueController::class, 'edit'])->name('issues.edit');
            Route::put('issues/{issue}', [IssueController::class, 'update'])->name('issues.update');
            Route::patch('issues/{issue}/status', [IssueController::class, 'updateStatus'])->name('issues.updateStatus');
            Route::delete('issues/{issue}', [IssueController::class, 'destroy'])->name('issues.destroy');

            Route::post('issues/{issue}/comments', [IssueCommentController::class, 'store'])->name('issues.comments.store');
            Route::delete('issues/{issue}/comments/{comment}', [IssueCommentController::class, 'destroy'])->name('issues.comments.destroy');
        });
    });
