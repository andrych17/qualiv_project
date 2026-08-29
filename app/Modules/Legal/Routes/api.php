<?php

use App\Http\Middleware\EnsureTenantStanding;
use App\Http\Middleware\InitializeTenancyByHeader;
use App\Modules\Legal\Controllers\Api\V1\FieldVisitApiController;
use Illuminate\Support\Facades\Route;

/**
 * LEGAL_SPECS.md §3M mobile surface. Nested under routes/api.php, so this inherits the
 * framework `api` group + `/api` prefix already — `field-visits` below resolves to
 * `GET /api/v1/legal/field-visits`.
 *
 * `InitializeTenancyByHeader` must run before `auth:sanctum` so the token lookup hits the
 * right tenant DB — enforced globally via the priority list in bootstrap/app.php regardless of
 * the order written here (same mechanism InitializeTenancyBySession relies on for the web).
 */
Route::prefix('v1/legal')
    ->middleware([InitializeTenancyByHeader::class, 'auth:sanctum', EnsureTenantStanding::class, 'module:LEGAL'])
    ->name('api.legal.')
    ->group(function () {
        Route::get('field-visits', [FieldVisitApiController::class, 'index'])->name('fieldVisits.index');
        Route::get('field-visits/{fieldVisit}', [FieldVisitApiController::class, 'show'])->name('fieldVisits.show');
        Route::post('field-visits/{fieldVisit}/check-in', [FieldVisitApiController::class, 'checkIn'])->name('fieldVisits.checkIn');
        Route::post('field-visits/{fieldVisit}/complete', [FieldVisitApiController::class, 'complete'])->name('fieldVisits.complete');
    });
