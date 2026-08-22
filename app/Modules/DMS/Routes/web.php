<?php

use App\Modules\DMS\Controllers\AuditLogController;
use App\Modules\DMS\Controllers\DocumentController;
use App\Modules\DMS\Controllers\FolderController;
use Illuminate\Support\Facades\Route;

// §3A ships — DMS's own dashboard is the section landing page, same convention as
// CRM/WNE's routes files (keep this, SysConfigSeeder's DMS menu_link, and
// AppBreadcrumb.vue's SECTION_HOME in sync).
Route::redirect('/dms', '/dms/dashboard');

Route::middleware(['auth', 'verified', 'module:DMS', 'menu.perm:DMS'])
    ->prefix('dms')
    ->name('dms.')
    ->group(function () {
        Route::get('dashboard', [DocumentController::class, 'index'])->name('dashboard');

        // §3B — 'create'/'edit' registered before the '{document}' show route so they aren't
        // swallowed as a route-model-bound id (same ordering constraint any resource route has).
        Route::get('documents/create', [DocumentController::class, 'create'])->name('documents.create');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
        Route::put('documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
        Route::post('documents/{document}/versions', [DocumentController::class, 'storeVersion'])->name('documents.versions.store');

        // §3C Version History Viewer — full list + restore. Compare is client-side (all
        // version metadata is already on this page's props, no extra endpoint needed).
        Route::get('documents/{document}/versions', [DocumentController::class, 'versions'])->name('documents.versions');
        Route::post('documents/{document}/versions/{version}/restore', [DocumentController::class, 'restoreVersion'])->name('documents.versions.restore');

        // §3H Object Relation Engine — add/remove a link between two documents. The read side
        // (both directions merged) already ships as part of documents.show's JSON payload.
        Route::post('documents/{document}/relations', [DocumentController::class, 'storeRelation'])->name('documents.relations.store');
        Route::delete('documents/{document}/relations/{relation}', [DocumentController::class, 'destroyRelation'])->name('documents.relations.destroy');

        Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

        Route::get('versions/{version}/file', [DocumentController::class, 'versionFile'])->name('versions.file');

        // §3D Folder / Category Management — 'create'/'{folder}' ordering constraint same as §3B above.
        Route::get('folders', [FolderController::class, 'index'])->name('folders.index');
        Route::get('folders/create', [FolderController::class, 'create'])->name('folders.create');
        Route::post('folders', [FolderController::class, 'store'])->name('folders.store');
        Route::get('folders/{folder}/edit', [FolderController::class, 'edit'])->name('folders.edit');
        Route::put('folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
        Route::delete('folders/{folder}', [FolderController::class, 'destroy'])->name('folders.destroy');

        // §3I Audit Trail — tenant-wide view; the §3A drawer's own Audit Log tab is a
        // capped per-document preview that links out here, same convention as §3C's Versions.
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log');
    });
