<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3A / §4 — Production Order. Single header for both production models;
 * `bom_id`/`recipe_id` are REAL cross-schema FKs into PP.pp_boms/PP.pp_recipes (§3B boundary
 * note — PP owns material composition), `routing_id` stays MES's own (§3E). Per MES_SPECS.sql's
 * DDL for this section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_prod_order_hdrs', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 30)->unique(); // SYSCONFIG.config_snums MES_MO_LASTID (§5)
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->string('production_model', 10); // assembly | process — app-validated, immutable after creation
            $table->foreignId('bom_id')->nullable()->constrained('PP.pp_boms'); // set when production_model = 'assembly'
            $table->foreignId('recipe_id')->nullable()->constrained('PP.pp_recipes'); // set when production_model = 'process'
            $table->foreignId('routing_id')->nullable()->constrained('MES.mes_routings'); // discrete only (§3E)
            $table->decimal('qty', 18, 4);
            $table->string('uom_code', 10)->nullable();
            $table->timestampTz('planned_start')->nullable();
            $table->timestampTz('planned_end')->nullable();
            $table->timestampTz('actual_start')->nullable();
            $table->timestampTz('actual_end')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->foreignId('warehouse_id')->nullable()->constrained('INVENTORY.warehouses');
            $table->string('line_area', 100)->nullable();
            $table->string('status', 15)->default('draft'); // draft | released | in_progress | paused | completed | cancelled
            $table->foreignId('parent_order_id')->nullable()->constrained('MES.mes_prod_order_hdrs');
            $table->string('source_type', 50)->nullable(); // informational polymorphic pointer, e.g. 'pp.pp_planned_orders'
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'production_model']);
            $table->index('product_id');
        });

        DB::statement(
            'ALTER TABLE "MES".mes_prod_order_hdrs ADD CONSTRAINT chk_mes_prod_order_hdrs_bom CHECK (production_model <> \'assembly\' OR bom_id IS NOT NULL)'
        );
        DB::statement(
            'ALTER TABLE "MES".mes_prod_order_hdrs ADD CONSTRAINT chk_mes_prod_order_hdrs_recipe CHECK (production_model <> \'process\' OR recipe_id IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_prod_order_hdrs');
    }
};
