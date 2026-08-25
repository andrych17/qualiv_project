<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Batch / Lot Tracking (§3L, Operational). A lot number is unique within
 * its product (two different products can both have a lot "A1"), never globally — matches
 * how a physical warehouse actually labels stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->string('batch_number', 60);
            $table->date('expiry_date')->nullable();
            $table->date('manufacture_date')->nullable();
            $table->string('supplier_reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'batch_number']);
            $table->index('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.stock_batches');
    }
};
