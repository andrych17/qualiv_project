<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAYROLL module schema — creates the "PAYROLL" PostgreSQL schema for tenant databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS "PAYROLL"');
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS "PAYROLL" CASCADE');
    }
};
