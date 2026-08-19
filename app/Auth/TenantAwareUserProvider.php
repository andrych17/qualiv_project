<?php

namespace App\Auth;

use App\Models\User;
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

        $user = parent::retrieveById($identifier);

        // Deactivating a user must end their active session on the next request, not
        // just block future logins.
        if ($user instanceof User && ! $user->is_active) {
            return null;
        }

        return $user;
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
