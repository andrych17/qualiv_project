<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Middleware\InitializeTenancyByHeader;
use Illuminate\Support\Facades\Route;

/**
 * Platform-level (Core) bearer-token auth for API clients — versioned per CLAUDE.md §2's
 * Web-vs-future-clients boundary ("Mobile / Tablet / external clients: versioned REST APIs
 * that call the same Services"). First real consumer is Legal's field-visit mobile surface
 * (LEGAL_SPECS.md §3M, routes required below), but auth/tenant-selection is not Legal's to
 * own — any future vertical's mobile client reuses this same login flow.
 *
 * Loaded via bootstrap/app.php's withRouting(api: ...), which wraps this whole file in the
 * framework `api` middleware group and an `/api` prefix — so `v1/auth/login` below resolves
 * to `POST /api/v1/auth/login`.
 */
Route::post('v1/auth/tenants', [AuthenticatedSessionController::class, 'lookup'])->name('api.auth.tenants');
Route::post('v1/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

Route::middleware([InitializeTenancyByHeader::class, 'auth:sanctum'])
    ->post('v1/auth/logout', [AuthController::class, 'logout'])
    ->name('api.auth.logout');

require app_path('Modules/Legal/Routes/api.php');
