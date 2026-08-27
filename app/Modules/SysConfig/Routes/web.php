<?php

use App\Modules\CustomFields\Controllers\FieldDefController;
use App\Modules\SysConfig\Controllers\ConfigConstController;
use App\Modules\SysConfig\Controllers\ConfigGroupController;
use App\Modules\SysConfig\Controllers\ConfigMenuController;
use App\Modules\SysConfig\Controllers\ConfigSnumController;
use App\Modules\SysConfig\Controllers\ConfigThemeController;
use App\Modules\SysConfig\Controllers\ConfigUserController;
use App\Modules\SysConfig\Controllers\TenantModuleController;
use Illuminate\Support\Facades\Route;

// Bare /config → first SysConfig screen (avoids 404 when link/bookmark drops the child path)
Route::redirect('/config', '/config/menus');

// SysConfig admin screens — ADMIN trustee on CONFIG_* menus
Route::middleware(['auth', 'verified'])->prefix('config')->name('config.')->group(function () {
    Route::middleware('menu.perm:CONFIG_THEME')->group(function () {
        Route::get('theme', [ConfigThemeController::class, 'index'])->name('theme.index');
        Route::post('theme', [ConfigThemeController::class, 'update'])->name('theme.update');
    });
    Route::middleware('menu.perm:CONFIG_MENUS')->group(function () {
        Route::delete('menus/bulk-destroy', [ConfigMenuController::class, 'bulkDestroy'])->name('menus.bulkDestroy');
        Route::resource('menus', ConfigMenuController::class)->except(['show']);
    });
    Route::middleware('menu.perm:CONFIG_GROUPS')->group(function () {
        Route::delete('groups/bulk-destroy', [ConfigGroupController::class, 'bulkDestroy'])->name('groups.bulkDestroy');
        Route::resource('groups', ConfigGroupController::class)->except(['show']);
    });
    Route::middleware('menu.perm:CONFIG_CONSTS')->group(function () {
        Route::delete('consts/bulk-destroy', [ConfigConstController::class, 'bulkDestroy'])->name('consts.bulkDestroy');
        Route::patch('consts/{configConst}/quick-update', [ConfigConstController::class, 'quickUpdate'])->name('consts.quickUpdate');
        Route::resource('consts', ConfigConstController::class)
            ->except(['show'])
            ->parameters(['consts' => 'configConst']);
    });
    Route::middleware('menu.perm:CONFIG_SERIALS')->group(function () {
        Route::delete('serials/bulk-destroy', [ConfigSnumController::class, 'bulkDestroy'])->name('serials.bulkDestroy');
        Route::resource('serials', ConfigSnumController::class)
            ->except(['show'])
            ->parameters(['serials' => 'configSnum']);
    });
    Route::middleware('menu.perm:CONFIG_MODULES')->group(function () {
        Route::get('modules', [TenantModuleController::class, 'index'])->name('modules.index');
        Route::patch('modules/{module}', [TenantModuleController::class, 'update'])->name('modules.update');
    });
    Route::middleware('menu.perm:CONFIG_FIELDS')->group(function () {
        Route::delete('fields/bulk-destroy', [FieldDefController::class, 'bulkDestroy'])->name('fields.bulkDestroy');
        Route::resource('fields', FieldDefController::class)
            ->except(['show'])
            ->parameters(['fields' => 'fieldDef']);
    });
    Route::middleware('menu.perm:CONFIG_USERS')->group(function () {
        Route::delete('users/bulk-destroy', [ConfigUserController::class, 'bulkDestroy'])->name('users.bulkDestroy');
        Route::patch('users/{user}/activate', [ConfigUserController::class, 'activate'])->name('users.activate');
        Route::patch('users/{user}/reset-password', [ConfigUserController::class, 'resetPassword'])->name('users.resetPassword');
        Route::resource('users', ConfigUserController::class)->except(['show']);
    });
});
