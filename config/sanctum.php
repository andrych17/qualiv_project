<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Unused here — this app has no first-party SPA sharing cookies with the API.
    | Every client (the Legal field-visit mobile surface, §3M) authenticates with a
    | bearer token issued by POST /api/v1/auth/login, never cookie-based sessions.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Empty on purpose: Laravel\Sanctum\Guard checks each of these guards' session
    | state before falling back to the bearer token. API requests never carry a
    | session (no `web` middleware on routes/api.php), so this would only be dead
    | weight per request.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | 30 days by default — a field operator re-authenticating every trip to a rural
    | BPN office with poor connectivity is exactly the friction §3M exists to avoid.
    | Revoke early via POST /api/v1/auth/logout if a device is lost.
    |
    */

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 43200),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Only referenced when a stateful SPA is configured (see 'stateful' above) —
    | left at Sanctum's defaults since this app doesn't use that path.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
