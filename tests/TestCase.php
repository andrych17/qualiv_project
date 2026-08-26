<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // docker-compose injects APP_ENV=local / DB_DATABASE=nusaevo; force test config before boot.
        foreach ([
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'DB_CONNECTION' => 'pgsql',
            // TEST_DB_HOST lets a non-docker host (e.g. native Windows) point at 127.0.0.1;
            // unset everywhere else, so docker-compose keeps resolving the "postgres" alias.
            'DB_HOST' => getenv('TEST_DB_HOST') ?: 'postgres',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'nusaevo_testing',
            // Dedicated test role (SUPERUSER so teardown can DROP ... WITH (FORCE)
            // even when GUI/backup clients on the shared dev instance hold
            // connections to tenant_* mid-run). Create once on the instance:
            //   CREATE ROLE nusa_test LOGIN PASSWORD 'secret' SUPERUSER CREATEDB;
            'DB_USERNAME' => getenv('TEST_DB_USERNAME') ?: 'nusa_test',
            'DB_PASSWORD' => 'secret',
            'REDIS_HOST' => 'redis',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutVite();
    }
}
