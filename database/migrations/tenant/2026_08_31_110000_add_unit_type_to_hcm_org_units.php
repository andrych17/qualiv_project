<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HCM_SPECS.md §3C already describes `HCM.org_units` as "tree (department/division/branch)"
 * but the table itself carried no field to tell those apart, so the sidebar's separate
 * Departments/Branches menu items had nothing to filter on. Adds that discriminator; existing
 * rows default to 'department' (the common case) and are editable individually afterward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('HCM.org_units', function (Blueprint $table) {
            $table->string('unit_type', 20)->default('department')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('HCM.org_units', function (Blueprint $table) {
            $table->dropColumn('unit_type');
        });
    }
};
