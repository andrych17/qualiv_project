<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3N: "Rework: `disposition = rework` routes the quantity to a rework
 * operation/phase (reuses §3G/§3I's execution engine against a rework-flagged routing/recipe
 * step)." Not in MES_SPECS.sql's original `mes_routing_ops` DDL — added here as the flag that
 * rule describes, same pattern as `auto_issue_components`
 * (2026_09_02_160000_add_mes_routing_ops_auto_issue.php). Assembly (routing ops) only in this
 * build — a process-model equivalent would need the same flag on `mes_process_phases` plus a
 * way to start a batch mid-sequence, a larger change than this section asks for on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('MES.mes_routing_ops', function (Blueprint $table) {
            $table->boolean('is_rework_destination')->default(false)->after('auto_issue_components');
        });
    }

    public function down(): void
    {
        Schema::table('MES.mes_routing_ops', function (Blueprint $table) {
            $table->dropColumn('is_rework_destination');
        });
    }
};
