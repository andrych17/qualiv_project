<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3H / §4 — Serial Genealogy. Records which components went into which finished
 * serial, as each is consumed/completed. MES does not own the serial identity — `stock_serials`
 * (Inventory) is that; this table only records the parent→component linkage. Per
 * MES_SPECS.sql's DDL for this section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_serial_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('serial_id')->nullable()->constrained('INVENTORY.stock_serials'); // the finished serial
            $table->foreignId('component_serial_id')->nullable()->constrained('INVENTORY.stock_serials');
            $table->foreignId('component_lot_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->foreignId('material_product_id')->constrained('INVENTORY.products');
            $table->foreignId('order_id')->constrained('MES.mes_prod_order_hdrs');
            $table->unsignedBigInteger('operation_ref')->nullable(); // mes_routing_ops.id
            $table->timestampTz('created_at')->useCurrent();

            $table->index('order_id');
        });

        DB::statement('ALTER TABLE "MES".mes_serial_links ADD CONSTRAINT chk_mes_serial_links_component CHECK (component_serial_id IS NOT NULL OR component_lot_id IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_serial_links');
    }
};
