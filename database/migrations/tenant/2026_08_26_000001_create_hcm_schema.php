<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HCM module schema — creates the "HCM" PostgreSQL schema for tenant databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "HCM"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "HCM" CASCADE');
    }
};
