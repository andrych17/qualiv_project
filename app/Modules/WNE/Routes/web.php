<?php

use App\Modules\WNE\Controllers\DeadLetterController;
use App\Modules\WNE\Controllers\MsgTemplateController;
use App\Modules\WNE\Controllers\PreferenceController;
use App\Modules\WNE\Controllers\TaskInboxController;
use App\Modules\WNE\Controllers\WneDashboardController;
use App\Modules\WNE\Controllers\WorkflowDefinitionController;
use App\Modules\WNE\Controllers\WorkflowStepController;
use App\Modules\WNE\Controllers\WorkflowTransitionController;
use Illuminate\Support\Facades\Route;

// §3A ships — WNE's own dashboard is now the section landing page. Keep this,
// SysConfigSeeder's WNE menu_link, and AppBreadcrumb.vue's SECTION_HOME in sync — same
// three-places convention CRM's routes file documents for its own dashboard landing page.
Route::redirect('/wne', '/wne/dashboard');

Route::middleware(['auth', 'verified', 'module:WNE', 'menu.perm:WNE'])
    ->prefix('wne')
    ->name('wne.')
    ->group(function () {
        // §3A — reached via the in-page sub-nav (WneSubNav), same one-menu-code-covers-every-
        // sub-page convention as every route below.
        Route::get('dashboard', [WneDashboardController::class, 'index'])->name('dashboard');

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

        // §3H — reached via the in-page sub-nav (WneSubNav), same "one menu code covers every
        // sub-page" convention CRM documents for its own CrmSubNav — no separate SysConfigSeeder row.
        Route::get('my-tasks', [TaskInboxController::class, 'index'])->name('my-tasks.index');
        Route::post('my-tasks/{instanceStep}/complete', [TaskInboxController::class, 'complete'])->name('my-tasks.complete');

        // §3L — same in-page sub-nav convention as My Approvals above.
        Route::resource('templates', MsgTemplateController::class)->except(['show']);
        Route::post('templates/{template}/activate', [MsgTemplateController::class, 'activate'])->name('templates.activate');
        Route::post('templates/{template}/deactivate', [MsgTemplateController::class, 'deactivate'])->name('templates.deactivate');
        Route::post('templates-preview', [MsgTemplateController::class, 'preview'])->name('templates.preview');

        // §3J — same in-page sub-nav convention as My Approvals/Templates above.
        Route::get('preferences', [PreferenceController::class, 'index'])->name('preferences.index');
        Route::post('preferences', [PreferenceController::class, 'update'])->name('preferences.update');

        // §3M — the DLQ tab; §3A's Dashboard shows a read-only capped preview of the same data.
        Route::get('dead-letters', [DeadLetterController::class, 'index'])->name('dead-letters.index');
        Route::post('dead-letters/{deadLetter}/resend', [DeadLetterController::class, 'resend'])->name('dead-letters.resend');
        Route::post('dead-letters/{deadLetter}/discard', [DeadLetterController::class, 'discard'])->name('dead-letters.discard');
    });
