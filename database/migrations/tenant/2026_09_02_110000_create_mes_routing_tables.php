<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3E / §4 — Routing / Operations (discrete). Per MES_SPECS.sql's DDL for this
 * section. Only one `is_active` routing version per product (DB partial unique index), same
 * technique PP.pp_boms already uses for its own "one active version per product" rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_routings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'version']);
        });

        DB::statement('CREATE UNIQUE INDEX uq_mes_routings_one_active_per_product ON "MES".mes_routings (product_id) WHERE is_active');

        Schema::create('MES.mes_routing_ops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routing_id')->constrained('MES.mes_routings')->cascadeOnDelete();
            $table->integer('seq');
            $table->string('op_code', 30);
            $table->string('op_name', 150);
            $table->foreignId('work_center_id')->constrained('MES.mes_work_centers');
            $table->integer('setup_time_minutes')->default(0);
            $table->integer('run_time_minutes')->default(0);
            $table->integer('queue_time_minutes')->default(0);
            $table->decimal('standard_output_qty', 18, 4)->nullable();
            $table->text('instructions')->nullable();

            $table->unique(['routing_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_routing_ops');
        Schema::dropIfExists('MES.mes_routings');
    }
};
