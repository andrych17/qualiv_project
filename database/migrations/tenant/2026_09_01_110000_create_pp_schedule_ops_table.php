<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3H — Detailed Scheduling, per PP_SPECS.sql's DDL for this section. `resource_type`
 * is deliberately narrower than pp_capacity_plans' own enum — no `pp_resource` option — since
 * this board schedules the machine/work-center/station an operation runs on; those identities
 * stay in MES (not built yet), so `resource_ref_id` is informational only, same posture as
 * pp_capacity_plans' mes_* rows. `scenario_id` has no FK (PP.pp_scenarios is Phase 3, not built)
 * — same nullable-placeholder posture as every other planning table in this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_schedule_ops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_order_id')->constrained('PP.pp_planned_orders')->cascadeOnDelete();
            $table->integer('seq')->default(1);
            $table->string('resource_type', 20)->nullable(); // mes_work_center | mes_machine | mes_station — informational
            $table->unsignedBigInteger('resource_ref_id')->nullable();
            $table->timestampTz('planned_start');
            $table->timestampTz('planned_end');
            $table->string('status', 10)->default('draft'); // draft | committed | released
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('planned_order_id');
            $table->index(['resource_type', 'resource_ref_id', 'planned_start', 'planned_end'], 'idx_pp_schedule_ops_resource_window');
        });

        DB::statement("ALTER TABLE \"PP\".pp_schedule_ops ADD CONSTRAINT chk_pp_schedule_ops_resource_type CHECK (resource_type IS NULL OR resource_type IN ('mes_work_center', 'mes_machine', 'mes_station'))");
        DB::statement("ALTER TABLE \"PP\".pp_schedule_ops ADD CONSTRAINT chk_pp_schedule_ops_status CHECK (status IN ('draft', 'committed', 'released'))");
        DB::statement('ALTER TABLE "PP".pp_schedule_ops ADD CONSTRAINT chk_pp_schedule_ops_window CHECK (planned_end > planned_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_schedule_ops');
    }
};
