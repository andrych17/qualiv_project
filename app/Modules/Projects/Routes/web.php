<?php

use App\Modules\Projects\Controllers\IssueCommentController;
use App\Modules\Projects\Controllers\IssueController;
use App\Modules\Projects\Controllers\ProjectController;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'module:PROJECTS', 'menu.perm:PROJECTS'])
    ->group(function () {
        Route::delete('projects/bulk-destroy', [ProjectController::class, 'bulkDestroy'])->name('projects.bulkDestroy');
        Route::resource('projects', ProjectController::class)->names('projects');

        Route::prefix('projects/{project}')->name('projects.')->group(function () {
            // Browser hits GET /projects/{project}/issues when someone types the URL or
            // refreshes after the quick-create POST — redirect to the board instead of 405.
            Route::get('issues', fn (Project $project) => redirect()->route('projects.show', $project))->name('issues.index');
            Route::post('issues', [IssueController::class, 'store'])->name('issues.store');
            Route::get('issues/{issue}/edit', [IssueController::class, 'edit'])->name('issues.edit');
            Route::put('issues/{issue}', [IssueController::class, 'update'])->name('issues.update');
            Route::patch('issues/{issue}/status', [IssueController::class, 'updateStatus'])->name('issues.updateStatus');
            Route::delete('issues/{issue}', [IssueController::class, 'destroy'])->name('issues.destroy');

            Route::post('issues/{issue}/comments', [IssueCommentController::class, 'store'])->name('issues.comments.store');
            Route::delete('issues/{issue}/comments/{comment}', [IssueCommentController::class, 'destroy'])->name('issues.comments.destroy');

            Route::post('issues/{issue}/attachments', [IssueController::class, 'storeAttachment'])->name('issues.attachments.store');
            Route::get('issues/{issue}/attachments/{attachment}/download', [IssueController::class, 'downloadAttachment'])->name('issues.attachments.download');
            Route::delete('issues/{issue}/attachments/{attachment}', [IssueController::class, 'destroyAttachment'])->name('issues.attachments.destroy');
        });
    });
