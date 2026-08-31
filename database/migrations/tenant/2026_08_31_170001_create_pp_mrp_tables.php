<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3D MRP Engine & Planned Orders, per PP_SPECS.sql's DDL for this section.
 * `scenario_id` has no FK (PP.pp_scenarios is Phase 3, not built) — same nullable-placeholder
 * posture as `pp_demand_lines.scenario_id` (§3B). `released_subject_type`/`_id` are
 * deliberately informational (MES doesn't have real tables yet) — same discipline
 * PP_SPECS.sql itself documents for this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_mrp_runs', function (Blueprint $table) {
            $table->id();
            $table->timestampTz('run_at')->useCurrent();
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->string('status', 10)->default('running'); // running | completed | failed — app-validated
        });

        Schema::create('PP.pp_planned_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mrp_run_id')->nullable()->constrained('PP.pp_mrp_runs');
            $table->string('plan_number', 30)->unique();
            $table->string('order_type', 10); // production | purchase | transfer — app-validated
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->decimal('qty', 18, 4);
            $table->date('need_by_date');
            $table->string('source_type', 15)->nullable(); // demand_line | mps_line — informational
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('bom_id')->nullable()->constrained('PP.pp_boms');
            $table->foreignId('recipe_id')->nullable()->constrained('PP.pp_recipes');
            $table->string('status', 10)->default('planned'); // planned | firmed | released | cancelled
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->string('released_subject_type', 50)->nullable();
            $table->unsignedBigInteger('released_subject_id')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status', 'scenario_id']);
        });

        // §3D rule: a production order must carry the BOM or recipe it was exploded from.
        DB::statement('ALTER TABLE "PP".pp_planned_orders ADD CONSTRAINT chk_pp_planned_orders_production_source CHECK (order_type <> \'production\' OR bom_id IS NOT NULL OR recipe_id IS NOT NULL)');
        // §3D rule: release is baseline-only, enforced at the DB level too (not just the service).
        DB::statement('ALTER TABLE "PP".pp_planned_orders ADD CONSTRAINT chk_pp_planned_orders_release_baseline_only CHECK (status <> \'released\' OR scenario_id IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_planned_orders');
        Schema::dropIfExists('PP.pp_mrp_runs');
    }
};
