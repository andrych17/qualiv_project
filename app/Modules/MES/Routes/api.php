<?php

use App\Http\Middleware\EnsureTenantStanding;
use App\Http\Middleware\InitializeTenancyByHeader;
use App\Modules\MES\Controllers\Api\V1\IotIngestionApiController;
use Illuminate\Support\Facades\Route;

/**
 * MES_SPECS.md §3S — IoT / PLC Integration ingestion endpoint. Nested under routes/api.php
 * (Platform-level), same pattern as Legal's own api.php — `InitializeTenancyByHeader` before
 * `auth:sanctum` so the bearer token is looked up in the right tenant DB (bootstrap/app.php's
 * priority list enforces the order regardless of what's written here).
 */
Route::prefix('v1/mes')
    ->middleware([InitializeTenancyByHeader::class, 'auth:sanctum', EnsureTenantStanding::class, 'module:MES'])
    ->name('api.mes.')
    ->group(function () {
        Route::post('iot/ingest', [IotIngestionApiController::class, 'store'])->name('iot.ingest');
    });
