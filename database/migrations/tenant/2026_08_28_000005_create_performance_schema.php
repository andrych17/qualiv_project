<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** PERFORMANCE module schema — creates the "PERF" PostgreSQL schema for tenant databases (CLAUDE.md §7A). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "PERF"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "PERF" CASCADE');
    }
};
