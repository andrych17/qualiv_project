<?php

use App\Modules\Config\Controllers\ConfigConstController;
use App\Modules\Config\Controllers\ConfigGroupController;
use App\Modules\Config\Controllers\ConfigMenuController;
use App\Modules\Config\Controllers\ConfigUserController;
use Illuminate\Support\Facades\Route;

// SysConfig admin screens — ADMIN trustee on CONFIG_* menus
Route::middleware(['auth', 'verified'])->prefix('config')->name('config.')->group(function () {
    Route::middleware('menu.perm:CONFIG_MENUS')->group(function () {
        Route::resource('menus', ConfigMenuController::class)->except(['show']);
    });
    Route::middleware('menu.perm:CONFIG_GROUPS')->group(function () {
        Route::resource('groups', ConfigGroupController::class)->except(['show']);
    });
    Route::middleware('menu.perm:CONFIG_CONSTS')->group(function () {
        Route::resource('consts', ConfigConstController::class)
            ->except(['show'])
            ->parameters(['consts' => 'configConst']);
    });
    Route::middleware('menu.perm:CONFIG_USERS')->group(function () {
        Route::resource('users', ConfigUserController::class)->except(['show']);
    });
});
