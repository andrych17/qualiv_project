<?php

use App\Http\Middleware\InitializeTenancyByRouteParameter;
use App\Modules\WNE\Controllers\CallbackController;
use App\Modules\WNE\Controllers\SendGridWebhookController;
use App\Modules\WNE\Controllers\TwilioWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * §3G/§3O inbound webhook receivers — deliberately outside the `web` middleware group (no
 * session, no CSRF, no Inertia sharing: an external system calling this has none of those)
 * and outside Laravel's framework `api` group too (it needs a `RateLimiter` named `api` this
 * app has never registered — not worth adding just to boot one route). Registered directly
 * via bootstrap/app.php's withRouting(then: ...) with only the middleware each route needs.
 *
 * Tenant resolution can't be login-bound here (CLAUDE.md §4) — there is no session — so the
 * tenant id travels in the URL itself, the one deliberate exception to that rule. A tenant
 * points their SendGrid/Twilio dashboard's webhook URL at their own `{tenant}` segment.
 */
Route::post('/api/wne/{tenant}/callbacks/{token}', [CallbackController::class, 'handle'])
    ->middleware(InitializeTenancyByRouteParameter::class)
    ->name('wne.callbacks.handle');

Route::post('/api/wne/{tenant}/webhooks/sendgrid', [SendGridWebhookController::class, 'handle'])
    ->middleware(InitializeTenancyByRouteParameter::class)
    ->name('wne.webhooks.sendgrid');

Route::post('/api/wne/{tenant}/webhooks/twilio', [TwilioWebhookController::class, 'handle'])
    ->middleware(InitializeTenancyByRouteParameter::class)
    ->name('wne.webhooks.twilio');
