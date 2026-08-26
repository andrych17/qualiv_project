<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SALES module schema — creates the "SALES" PostgreSQL schema for tenant databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "SALES"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "SALES" CASCADE');
    }
};
