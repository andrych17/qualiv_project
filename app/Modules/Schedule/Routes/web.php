<?php

use App\Modules\Schedule\Controllers\EventController;
use App\Modules\Schedule\Controllers\ResourceController;
use App\Modules\Schedule\Controllers\ScheduleDashboardController;
use App\Modules\Schedule\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// §3A ships — the calendar dashboard is now the section landing page. Keep this,
// SysConfigSeeder's SCHEDULE menu_link, and AppBreadcrumb.vue's SECTION_HOME in sync —
// same three-places convention CRM/WNE/DMS each document for their own landing page.
Route::redirect('/schedule', '/schedule/dashboard');

Route::middleware(['auth', 'verified', 'module:SCHEDULE', 'menu.perm:SCHEDULE'])
    ->prefix('schedule')
    ->name('schedule.')
    ->group(function () {
        Route::get('dashboard', [ScheduleDashboardController::class, 'index'])->name('dashboard');
        Route::get('dashboard/item/{schedItem}', [ScheduleDashboardController::class, 'itemDrawer'])->name('dashboard.item');
        Route::post('dashboard/quick-create-task', [ScheduleDashboardController::class, 'quickCreateTask'])->name('dashboard.quickCreateTask');
        Route::post('dashboard/quick-create-event', [ScheduleDashboardController::class, 'quickCreateEvent'])->name('dashboard.quickCreateEvent');

        Route::post('tasks/{task}/mark-done', [TaskController::class, 'markDone'])->name('tasks.markDone');
        Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
        // §3F — per-occurrence actions on the Edit page's "Upcoming occurrences" panel.
        Route::post('tasks/{task}/occurrences/skip', [TaskController::class, 'skipOccurrence'])->name('tasks.occurrences.skip');
        Route::post('tasks/{task}/occurrences/reschedule', [TaskController::class, 'rescheduleOccurrence'])->name('tasks.occurrences.reschedule');
        Route::post('tasks/{task}/occurrences/restore', [TaskController::class, 'restoreOccurrence'])->name('tasks.occurrences.restore');
        Route::resource('tasks', TaskController::class)->except(['show']);

        Route::post('events/{event}/cancel', [EventController::class, 'cancel'])->name('events.cancel');
        Route::post('events/{event}/occurrences/skip', [EventController::class, 'skipOccurrence'])->name('events.occurrences.skip');
        Route::post('events/{event}/occurrences/reschedule', [EventController::class, 'rescheduleOccurrence'])->name('events.occurrences.reschedule');
        Route::post('events/{event}/occurrences/restore', [EventController::class, 'restoreOccurrence'])->name('events.occurrences.restore');
        Route::resource('events', EventController::class)->except(['show']);

        Route::resource('resources', ResourceController::class)->except(['show']);
    });
