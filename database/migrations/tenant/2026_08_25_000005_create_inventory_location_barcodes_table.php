<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Barcode Engine (§3K), the last MVP piece per the suggested build order.
 * `product_barcodes` already shipped with §3B (product master data); this adds its location
 * counterpart — same table pattern (`location_id` FK + tenant-unique `barcode`), no
 * type/unit_multiplier columns since those are product-specific concepts (case-pack scanning)
 * that don't apply to a bin label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.location_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('INVENTORY.locations')->cascadeOnDelete();
            $table->string('barcode', 64)->unique(); // unique per tenant, same rule as product_barcodes
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.location_barcodes');
    }
};
