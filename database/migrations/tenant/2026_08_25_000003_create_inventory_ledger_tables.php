<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Goods Receipt (§3D) / Goods Issue (§3E) and the stock ledger engine
 * they both post into. This is the "working ledger" the suggested build order (§5) calls
 * for: `stock_ledger` (append-only, immutable), `stock_valuation_layers` (§3J costing —
 * FIFO discrete layers, or a single re-priced layer per product/warehouse for Weighted
 * Average), and `stock_balances` (denormalized on-hand cache, rebuildable from the ledger).
 *
 * Batch/lot (§3L) and Barcode-scan wiring (§3K) are later builds per the suggested order —
 * no batch_id column here yet (MVP scope boundary, §5).
 *
 * No `company_id` anywhere in this schema: Inventory has no company concept of its own
 * (INVENTORY_SPECS.md never mentions one) and must keep working standalone with Accounting
 * uninstalled. The GL-posting seam (dispatching Accounting's InventoryGoodsReceived/
 * InventoryGoodsIssued events) resolves a company at post-time instead of storing one here
 * — see GoodsReceiptService/GoodsIssueService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('location_id')->constrained('INVENTORY.locations');
            $table->string('movement_type', 20); // receipt | issue | transfer | adjustment
            $table->decimal('qty', 18, 4); // signed, base UoM — positive in, negative out
            $table->decimal('unit_cost', 18, 6); // per base-UoM unit
            $table->decimal('total_value', 18, 4); // signed, qty * unit_cost
            // Polymorphic pointer to the originating document (goods_receipts/goods_issues
            // row, later transfers/adjustments) — NOT the header's own subject_type/
            // subject_id (which points at a PO/vendor/customer/sales-delivery instead).
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->date('movement_date');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'warehouse_id', 'location_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('movement_date');
        });

        Schema::create('INVENTORY.stock_valuation_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            // The receipt ledger row that created this layer (FIFO), or most recently
            // re-priced it (Average) — informational, not authoritative (unit_cost/
            // remaining_qty on this row are what costing actually reads).
            $table->foreignId('stock_ledger_id')->nullable()->constrained('INVENTORY.stock_ledger');
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('qty', 18, 4); // cumulative received into this layer — audit/display only
            $table->decimal('remaining_qty', 18, 4);
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id']);
        });

        Schema::create('INVENTORY.stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('location_id')->constrained('INVENTORY.locations');
            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id', 'location_id']);
        });

        Schema::create('INVENTORY.goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->date('receipt_date');
            // Optional polymorphic link to a Purchasing/vertical PO, or a vendor Partner
            // (CRM.partners) for a direct receipt, or null for "opening balance" (§3D).
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->string('reference_number', 60)->nullable();
            $table->string('status', 10)->default('draft'); // draft | posted
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('INVENTORY.goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained('INVENTORY.goods_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->decimal('qty', 18, 4); // as entered, in `uom_id`
            $table->foreignId('uom_id')->constrained('INVENTORY.uoms');
            $table->decimal('unit_cost', 18, 6); // as entered, per `uom_id` unit
            $table->foreignId('destination_location_id')->constrained('INVENTORY.locations');
        });

        Schema::create('INVENTORY.goods_issues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->date('issue_date');
            // Optional link to a Sales order/vertical record, or a customer Partner
            // (CRM.partners), or null for internal consumption (§3E).
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->string('reason', 30)->nullable(); // consumption | sample | write_off_pending_adjustment_review
            $table->string('status', 10)->default('draft'); // draft | posted
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('INVENTORY.goods_issue_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_issue_id')->constrained('INVENTORY.goods_issues')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->decimal('qty', 18, 4); // as entered, in `uom_id`
            $table->foreignId('uom_id')->constrained('INVENTORY.uoms');
            $table->foreignId('source_location_id')->constrained('INVENTORY.locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.goods_issue_lines');
        Schema::dropIfExists('INVENTORY.goods_issues');
        Schema::dropIfExists('INVENTORY.goods_receipt_lines');
        Schema::dropIfExists('INVENTORY.goods_receipts');
        Schema::dropIfExists('INVENTORY.stock_balances');
        Schema::dropIfExists('INVENTORY.stock_valuation_layers');
        Schema::dropIfExists('INVENTORY.stock_ledger');
    }
};
