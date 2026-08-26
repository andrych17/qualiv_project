<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PURCHASE module schema — creates the "PURCHASE" PostgreSQL schema for tenant databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "PURCHASE"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "PURCHASE" CASCADE');
    }
};
