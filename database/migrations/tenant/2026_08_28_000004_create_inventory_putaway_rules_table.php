<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Put-away Rules (§3R, Operational). A rule's condition is exactly one of
 * `product_id` (specific product) or `category_id` (whole category) — never both, never
 * neither (app-layer enforced, same posture as Cycle Counting's scope). `priority_order`
 * (ascending, first-matching-rule wins) lets a specific-product rule outrank a broader
 * category rule for the same warehouse. Applied by `PutawayRuleService::resolve()` as the
 * default `destination_location_id` when a Goods Receipt (§3D) line is saved without one —
 * see `GoodsReceiptService::syncLines()` — and is always overridable there before posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.putaway_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('product_id')->nullable()->constrained('INVENTORY.products');
            $table->foreignId('category_id')->nullable()->constrained('INVENTORY.product_categories');
            $table->foreignId('target_location_id')->constrained('INVENTORY.locations');
            $table->integer('priority_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['warehouse_id', 'is_active', 'priority_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.putaway_rules');
    }
};
