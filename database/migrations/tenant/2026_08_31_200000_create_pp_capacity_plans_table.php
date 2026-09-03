<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3F — Capacity Planning (RCCP), per PP_SPECS.sql's DDL for this section. Rough-cut
 * only (Phase 1): required/available hours are planner-entered, not auto-exploded from routing
 * standard times (MES isn't built — no standard-time data exists yet) or auto-aggregated from
 * Schedule's `AvailabilityService` (which answers "is this slot free?", not "how many hours are
 * available in this period?" — no such aggregator exists there either). `resource_group_id` gets
 * a real FK (`pp_resource_groups` exists in this same schema); `resource_ref_id` stays
 * unconstrained since it's polymorphic by `resource_type`, same §3E discipline. Only
 * `created_at` exists (no `updated_at`) — matches this spec's own SQL exactly, same posture as
 * `pp_mrp_runs.run_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_capacity_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_group_id')->nullable()->constrained('PP.pp_resource_groups');
            $table->string('resource_type', 20)->nullable(); // mes_work_center | mes_machine | pp_resource — app-validated
            $table->unsignedBigInteger('resource_ref_id')->nullable(); // informational (mes_*) or PP.pp_resources.id (pp_resource)
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('required_hours', 10, 2);
            $table->decimal('available_hours', 10, 2);
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['period_start', 'period_end', 'scenario_id'], 'idx_pp_capacity_plans_period');
        });

        DB::statement('ALTER TABLE "PP".pp_capacity_plans ADD CONSTRAINT chk_pp_capacity_plans_target CHECK (resource_group_id IS NOT NULL OR resource_ref_id IS NOT NULL)');
        DB::statement('ALTER TABLE "PP".pp_capacity_plans ADD CONSTRAINT chk_pp_capacity_plans_period_order CHECK (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_capacity_plans');
    }
};
