<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Mode B: one DB per tenant; module isolation is PostgreSQL schemas inside that DB.
 * stancl only creates the DB — schemas are app-owned.
 */
class CreateModuleSchemas
{
    /** @var list<string> */
    public const SCHEMAS = [
        'SYSCONFIG',
        'INVENTORY',
        'CRM',
        'SCHEDULE',
        'WNE',
        'LEGAL',
        'CUSTOMFIELDS',
        'PROJECTS',
        'DMS',
        'ACCOUNTING',
        'SALES',
        'PURCHASE',
        'HCM',
        'PAYROLL',
        'PERF',
        'AIINSIGHT',
        'PP',
        'MES',
        'POS',
    ];

    public function __construct(
        protected TenantWithDatabase $tenant,
    ) {}

    public function handle(): void
    {
        $this->tenant->run(function () {
            // Grant to the app's own DB role, not PUBLIC — PUBLIC silently disables schema
            // isolation the moment a second DB role/user is ever added to this Postgres instance.
            $role = DB::connection()->getConfig('username');

            foreach (self::SCHEMAS as $schema) {
                DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
                // ponytail: grant schema & table permissions to avoid PostgreSQL 42501 permission denied errors
                DB::statement('GRANT ALL ON SCHEMA "'.$schema.'" TO "'.$role.'"');
                DB::statement('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA "'.$schema.'" TO "'.$role.'"');
                DB::statement('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA "'.$schema.'" TO "'.$role.'"');
                DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA "'.$schema.'" GRANT ALL ON TABLES TO "'.$role.'"');
            }
        });
    }
}
