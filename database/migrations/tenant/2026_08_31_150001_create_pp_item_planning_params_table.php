<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3A / §4 — one planning-only companion row per INVENTORY.products item.
 * Columns per PP_SPECS.sql. `preferred_line_ref_id`/`alternate_line_ref_id` are informational
 * (MES.mes_work_centers) until MES itself ships — not an FK yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_item_planning_params', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('INVENTORY.products');
            $table->string('make_type', 3)->default('mts'); // mts | mto — app-validated (Request rule)
            $table->decimal('min_lot_qty', 18, 4)->nullable();
            $table->decimal('max_lot_qty', 18, 4)->nullable();
            $table->decimal('fixed_lot_qty', 18, 4)->nullable();
            $table->decimal('economic_lot_qty', 18, 4)->nullable();
            $table->decimal('safety_stock_qty', 18, 4)->default(0);
            $table->integer('lead_time_days')->default(0);
            $table->integer('planning_lead_time_days')->default(0);
            $table->decimal('order_multiple', 18, 4)->nullable();
            $table->decimal('scrap_pct', 5, 2)->default(0);
            $table->decimal('yield_pct_override', 5, 2)->nullable();
            $table->string('production_calendar_ref', 100)->nullable();
            $table->string('preferred_line_type', 20)->nullable(); // 'mes_work_center' — app-validated
            $table->unsignedBigInteger('preferred_line_ref_id')->nullable();
            $table->unsignedBigInteger('alternate_line_ref_id')->nullable();
            $table->integer('planning_fence_days')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_item_planning_params');
    }
};
