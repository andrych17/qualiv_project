<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Login-bound tenancy: app routes live in routes/web.php + session middleware.
| Domain-based tenant routes intentionally unused.
*/
Route::middleware('web')->group(function () {
    //
});
