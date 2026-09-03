<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3C — Master Production Schedule grid, per PP_SPECS.sql's DDL for this section.
 * `scenario_id` has no FK (PP.pp_scenarios is Phase 3, not built) — same nullable-placeholder
 * posture as `pp_demand_lines`/`pp_planned_orders`. Unlike those, `released_planned_order_id`
 * DOES get a real FK: `pp_planned_orders` already exists in this same schema (§3D), so the
 * "informational, no FK" posture used for MES pointers elsewhere doesn't apply here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_mps_hdrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'scenario_id']);
        });

        Schema::create('PP.pp_mps_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mps_hdr_id')->constrained('PP.pp_mps_hdrs')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('planned_qty', 18, 4);
            $table->boolean('is_frozen')->default(false); // freeze fence control (§3C)
            $table->foreignId('released_planned_order_id')->nullable()->constrained('PP.pp_planned_orders'); // set once released (§3C release action)
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->timestamps();

            $table->unique(['mps_hdr_id', 'period_start']);
        });

        \Illuminate\Support\Facades\DB::statement('ALTER TABLE "PP".pp_mps_lines ADD CONSTRAINT chk_pp_mps_lines_period_order CHECK (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_mps_lines');
        Schema::dropIfExists('PP.pp_mps_hdrs');
    }
};
