<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Users live in tenant DBs only. Never hit central `users` without tenancy.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        if (! tenancy()->initialized) {
            return null;
        }

        return parent::retrieveById($identifier);
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        if (! tenancy()->initialized) {
            return null;
        }

        return parent::retrieveByToken($identifier, $token);
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        if (! tenancy()->initialized) {
            return null;
        }

        return parent::retrieveByCredentials($credentials);
    }
}
