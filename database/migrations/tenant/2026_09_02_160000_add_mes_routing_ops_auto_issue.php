<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3G Rules/Logic: "COMPLETE... if the operation is configured to auto-issue
 * components 1:1 with standard BOM usage, calls Material Consumption (§3J)." Not in
 * MES_SPECS.sql's original `mes_routing_ops` DDL — added here as the config switch that rule
 * describes. Defaults `true` so an existing routing's ops behave as most tenants want out of
 * the box; a routing that shouldn't auto-consume (e.g. a pure inspection/test step) flips it
 * off per-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('MES.mes_routing_ops', function (Blueprint $table) {
            $table->boolean('auto_issue_components')->default(true)->after('standard_output_qty');
        });
    }

    public function down(): void
    {
        Schema::table('MES.mes_routing_ops', function (Blueprint $table) {
            $table->dropColumn('auto_issue_components');
        });
    }
};
