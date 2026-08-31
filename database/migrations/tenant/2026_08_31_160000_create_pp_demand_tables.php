<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3B Demand Aggregation. `pp_demand_forecasts` is master data (one row per
 * product/period, manual or imported); each forecast row syncs a 1:1 `pp_demand_hdrs`/
 * `pp_demand_lines` pair (DemandAggregationService::syncForecastDemand()), same for a Sales
 * order (one header per order) and a safety-stock shortfall (one header per
 * `pp_item_planning_params` row) — `subject_type`/`subject_id` on the header points back to
 * whichever of those produced it, `null` for a planner-entered manual header.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->date('period_start');
            $table->decimal('qty', 18, 4)->default(0);
            $table->string('source', 10)->default('manual'); // manual | import — app-validated
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['product_id', 'period_start']);
        });

        Schema::create('PP.pp_demand_hdrs', function (Blueprint $table) {
            $table->id();
            // manual | forecast | sales_order | safety_stock | blanket_order | dependent | transfer
            // — only manual/forecast/sales_order/safety_stock have an active producer today
            // (§3B: blanket orders aren't a built Sales feature yet, dependent demand needs
            // §3D's MRP explosion, transfer demand needs a real INVENTORY.stock_reservations
            // trigger — reserved enum values, not yet wired).
            $table->string('source_type', 20);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->date('demand_date');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('source_type');
        });

        Schema::create('PP.pp_demand_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demand_hdr_id')->constrained('PP.pp_demand_hdrs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->date('need_by_date');
            $table->decimal('qty', 18, 4);
            // Phase 3 placeholder (PP_SPECS.md §5 Scenario Isolation) — always null until
            // pp_scenarios (§3N) ships; no FK yet since that table doesn't exist.
            $table->unsignedBigInteger('scenario_id')->nullable();
            $table->timestamps();

            $table->index('product_id');
            $table->index('scenario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_demand_lines');
        Schema::dropIfExists('PP.pp_demand_hdrs');
        Schema::dropIfExists('PP.pp_demand_forecasts');
    }
};
