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
        'NOTIFICATIONS',
        'WORKFLOW',
        'LEGAL',
        'CUSTOMFIELDS',
    ];

    public function __construct(
        protected TenantWithDatabase $tenant,
    ) {}

    public function handle(): void
    {
        $this->tenant->run(function () {
            foreach (self::SCHEMAS as $schema) {
                DB::statement('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
            }
        });
    }
}
