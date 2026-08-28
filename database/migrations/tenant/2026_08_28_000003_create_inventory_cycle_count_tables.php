<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Cycle Counting (§3Q, Operational). A count is scoped exactly one of three
 * ways — a single location, a product category, or a manual ABC class (`products.abc_class`,
 * added alongside this migration) — never more than one, since the scopes overlap in what they
 * could match. Lines are generated at creation time from matching `stock_balances` rows (one
 * line per product/location/batch), each pinning its own `location_id` — unlike Adjustment's
 * single header-level location, a category/ABC-scoped count can span the whole warehouse.
 *
 * `system_qty` is a display-only snapshot, same posture as `adjustment_lines.system_qty` —
 * CycleCountService::complete() hands counted lines to `AdjustmentService::create()`, which
 * re-reads the live balance at post() time, never this snapshot.
 *
 * No ledger/balance columns here: "counting itself never silently changes stock" (§3Q) —
 * completing with variances only drafts Adjustments (§3G) for review/approval, grouped one per
 * counted location since that's Adjustment's own scoping unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.cycle_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            // Exactly one of these three is set — the count's scope (app-layer enforced).
            $table->foreignId('location_id')->nullable()->constrained('INVENTORY.locations');
            $table->foreignId('category_id')->nullable()->constrained('INVENTORY.product_categories');
            $table->string('abc_class', 1)->nullable();
            $table->string('status', 20)->default('pending'); // pending | in_progress | completed
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->date('scheduled_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('INVENTORY.cycle_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_count_id')->constrained('INVENTORY.cycle_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('location_id')->constrained('INVENTORY.locations');
            $table->foreignId('batch_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->decimal('system_qty', 18, 4)->nullable();
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->string('status', 20)->default('pending'); // pending | counted
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users');

            $table->index(['cycle_count_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.cycle_count_lines');
        Schema::dropIfExists('INVENTORY.cycle_counts');
    }
};
