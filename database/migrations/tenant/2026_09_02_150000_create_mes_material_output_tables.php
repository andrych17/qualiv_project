<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3J / §4 — Material Consumption & Production Output (common to both production
 * models). Per MES_SPECS.sql's DDL for this section. No `updated_at` column on either table —
 * these are write-once transaction rows, one call to `InventoryService::issue()`/`receive()`
 * produces one row, same posture as `mes_prod_events` (§3C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_material_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('MES.mes_prod_order_hdrs');
            $table->unsignedBigInteger('operation_ref')->nullable(); // mes_routing_ops.id or the future mes_batch_phases.id
            $table->foreignId('material_product_id')->constrained('INVENTORY.products');
            $table->foreignId('lot_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->foreignId('serial_id')->nullable()->constrained('INVENTORY.stock_serials');
            $table->decimal('qty', 18, 4);
            $table->string('uom_code', 10)->nullable();
            $table->string('type', 10); // issue | return
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_id');
        });

        DB::statement('ALTER TABLE "MES".mes_material_consumptions ADD CONSTRAINT chk_mes_material_consumptions_type CHECK (type IN (\'issue\', \'return\'))');

        Schema::create('MES.mes_production_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('MES.mes_prod_order_hdrs');
            $table->unsignedBigInteger('operation_ref')->nullable();
            $table->string('output_type', 15); // finished | co_product | by_product | waste
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->decimal('qty', 18, 4);
            $table->string('uom_code', 10)->nullable();
            $table->foreignId('lot_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->foreignId('serial_id')->nullable()->constrained('INVENTORY.stock_serials');
            $table->string('reason_code', 30)->nullable(); // set when output_type = 'waste' (§3N)
            $table->string('disposition', 10)->nullable(); // scrap | rework
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_id');
        });

        DB::statement('ALTER TABLE "MES".mes_production_outputs ADD CONSTRAINT chk_mes_production_outputs_type CHECK (output_type IN (\'finished\', \'co_product\', \'by_product\', \'waste\'))');
        DB::statement('ALTER TABLE "MES".mes_production_outputs ADD CONSTRAINT chk_mes_production_outputs_disposition CHECK (disposition IS NULL OR disposition IN (\'scrap\', \'rework\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_production_outputs');
        Schema::dropIfExists('MES.mes_material_consumptions');
    }
};
