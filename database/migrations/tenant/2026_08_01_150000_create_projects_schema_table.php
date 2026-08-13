<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROJECTS module schema — created here so fresh tenants get the schema via
 * `tenants:migrate` instead of manual SQL (tenant_001/tenant_nusaevo were created
 * by hand before this migration existed; IF NOT EXISTS keeps them idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Quoted: unquoted CREATE SCHEMA folds to lowercase "projects", but tables
        // reference "PROJECTS"."projects" (quoted) — the names must match exactly.
        DB::statement('CREATE SCHEMA IF NOT EXISTS "PROJECTS"');
    }

    public function down(): void
    {
        // Tables are dropped by their own migrations first; CASCADE only cleans up
        // any leftover objects if a tenant was partially migrated.
        DB::statement('DROP SCHEMA IF EXISTS "PROJECTS" CASCADE');
    }
};
