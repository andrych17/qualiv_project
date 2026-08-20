<?php

use App\Modules\WNE\Controllers\WorkflowDefinitionController;
use App\Modules\WNE\Controllers\WorkflowStepController;
use App\Modules\WNE\Controllers\WorkflowTransitionController;
use Illuminate\Support\Facades\Route;

// §3B ships first (no §3A dashboard yet) — the workflow list is the landing page for now.
// Keep this, SysConfigSeeder's WNE menu_link, and any future §3A redirect in sync — same
// three-places convention CRM's routes file documents for its own dashboard landing page.
Route::redirect('/wne', '/wne/workflows');

Route::middleware(['auth', 'verified', 'module:WNE', 'menu.perm:WNE'])
    ->prefix('wne')
    ->name('wne.')
    ->group(function () {
        // Route::resource's default param would be {workflow}, but every controller here
        // (including the non-resource routes below) names its argument $definition — this
        // renames the resource's implicit-binding param to match so it doesn't silently fall
        // back to an unbound empty model instead of throwing a 404 or resolving the row.
        Route::resource('workflows', WorkflowDefinitionController::class)->except(['show'])->parameters(['workflows' => 'definition']);
        Route::post('workflows/{definition}/publish', [WorkflowDefinitionController::class, 'publish'])->name('workflows.publish');
        Route::post('workflows/{definition}/unpublish', [WorkflowDefinitionController::class, 'unpublish'])->name('workflows.unpublish');

        Route::post('workflows/{definition}/steps', [WorkflowStepController::class, 'store'])->name('workflows.steps.store');
        Route::put('workflows/{definition}/steps/{step}', [WorkflowStepController::class, 'update'])->name('workflows.steps.update');
        Route::delete('workflows/{definition}/steps/{step}', [WorkflowStepController::class, 'destroy'])->name('workflows.steps.destroy');

        Route::post('workflows/{definition}/transitions', [WorkflowTransitionController::class, 'store'])->name('workflows.transitions.store');
        Route::delete('workflows/{definition}/transitions/{transition}', [WorkflowTransitionController::class, 'destroy'])->name('workflows.transitions.destroy');
    });
