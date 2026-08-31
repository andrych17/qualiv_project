<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PP module schema — created here (not only in CreateModuleSchemas) so tenants
 * provisioned before this module existed pick it up via `tenants:migrate`, same
 * precedent as ACCOUNTING (2026_08_23_000001_create_accounting_schema.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "PP"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "PP" CASCADE');
    }
};
