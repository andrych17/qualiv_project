<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ACCOUNTING module schema — created here (not only in CreateModuleSchemas) so
 * tenants provisioned before this module existed pick it up via `tenants:migrate`,
 * same precedent as PROJECTS (2026_08_01_150000_create_projects_schema_table.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "ACCOUNTING"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "ACCOUNTING" CASCADE');
    }
};
