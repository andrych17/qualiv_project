<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PURCHASE module — §3E Goods Receipt (GR); the authoritative record for the three-way match. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.pur_receipt_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('gr_no', 30)->unique();
            $table->foreignId('po_id')->constrained('PURCHASE.pur_order_hdrs');
            $table->foreignId('receiver_id')->nullable()->constrained('users');
            $table->timestamp('received_at')->useCurrent();
            // destination warehouse/location — shown only when Inventory installed (§3E)
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            // informational ref to INVENTORY.goods_receipts.id — not an enforced FK (§3E/§4)
            $table->unsignedBigInteger('inventory_goods_receipt_id')->nullable();
            $table->string('status', 15)->default('posted'); // posted|cancelled
            $table->text('discrepancy_notes')->nullable();
            $table->timestamps();

            $table->index('po_id');
        });

        Schema::create('PURCHASE.pur_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gr_id')->constrained('PURCHASE.pur_receipt_hdrs')->cascadeOnDelete();
            $table->foreignId('po_line_id')->constrained('PURCHASE.pur_order_lines');
            $table->decimal('quantity_received', 18, 4);
            $table->decimal('unit_cost', 18, 2)->nullable(); // defaults from PO line price, editable for landed-cost variance
            $table->text('condition_notes')->nullable();
            $table->boolean('over_receipt_flag')->default(false); // §3E: qty > ordered beyond tolerance
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_receipt_lines');
        Schema::dropIfExists('PURCHASE.pur_receipt_hdrs');
    }
};
