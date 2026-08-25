<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Serial Number Tracking (§3M, Operational). Unlike a lot number
 * (§3L, shared by many units, unique per product), a serial number identifies exactly one
 * unit and is unique across the whole tenant — matches how a manufacturer actually assigns
 * them (two different products never share a serial). `warehouse_id`/`location_id` are the
 * unit's current whereabouts and go null once issued (no longer "in" any warehouse);
 * `stock_ledger_id` points at whichever ledger row last moved it, giving a full history via
 * a join without a separate per-document pivot table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.stock_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->string('serial_number', 80)->unique();
            $table->string('status', 20)->default('in_stock');
            $table->foreignId('warehouse_id')->nullable()->constrained('INVENTORY.warehouses');
            $table->foreignId('location_id')->nullable()->constrained('INVENTORY.locations');
            $table->foreignId('stock_ledger_id')->nullable()->constrained('INVENTORY.stock_ledger');
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.stock_serials');
    }
};
