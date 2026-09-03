<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MES module schema — created here (not only in CreateModuleSchemas) so tenants
 * provisioned before this module existed pick it up via `tenants:migrate`, same
 * precedent as PP (2026_08_31_150000_create_pp_schema.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "MES"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "MES" CASCADE');
    }
};
