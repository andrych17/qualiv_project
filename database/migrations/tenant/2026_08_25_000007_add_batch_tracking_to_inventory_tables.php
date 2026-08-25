<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Batch / Lot Tracking wiring (§3L). Adds a nullable `batch_id` onto
 * every table the ledger/costing engine already writes to, plus each document's line table —
 * always null for a non-batch product, so nothing about the MVP engine (§3D/§3E/§3F/§3G/§3J)
 * changes shape for tenants that never turn tracking on.
 *
 * `stock_balances`' uniqueness has to move from a plain composite constraint to an expression
 * index on `COALESCE(batch_id, 0)` — Postgres treats every NULL as distinct in a plain unique
 * index, so a plain `(product_id, warehouse_id, location_id, batch_id)` constraint would let
 * a non-batch product accumulate a duplicate balance row on every post (each NULL "conflicts"
 * with nothing). `StockBalanceService::lockOrCreate()`'s upsert targets this same expression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('INVENTORY.stock_ledger', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('location_id')->constrained('INVENTORY.stock_batches');
        });

        Schema::table('INVENTORY.stock_valuation_layers', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('warehouse_id')->constrained('INVENTORY.stock_batches');
            $table->index(['product_id', 'warehouse_id', 'batch_id']);
        });

        Schema::table('INVENTORY.stock_balances', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('location_id')->constrained('INVENTORY.stock_batches');
            $table->dropUnique(['product_id', 'warehouse_id', 'location_id']);
        });
        DB::statement('CREATE UNIQUE INDEX uq_inventory_stock_balances_grain ON "INVENTORY".stock_balances (product_id, warehouse_id, location_id, (COALESCE(batch_id, 0)))');

        Schema::table('INVENTORY.goods_receipt_lines', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('INVENTORY.stock_batches');
        });

        Schema::table('INVENTORY.goods_issue_lines', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('INVENTORY.stock_batches');
            // §3L: expired-batch issues are blocked unless overridden — the reason is the
            // durable log (no separate audit-log subsystem for one field).
            $table->string('expiry_override_reason', 255)->nullable();
        });

        Schema::table('INVENTORY.transfer_lines', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('INVENTORY.stock_batches');
        });

        Schema::table('INVENTORY.adjustment_lines', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('INVENTORY.stock_batches');
        });
    }

    public function down(): void
    {
        Schema::table('INVENTORY.adjustment_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });

        Schema::table('INVENTORY.transfer_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });

        Schema::table('INVENTORY.goods_issue_lines', function (Blueprint $table) {
            $table->dropColumn('expiry_override_reason');
            $table->dropConstrainedForeignId('batch_id');
        });

        Schema::table('INVENTORY.goods_receipt_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });

        DB::statement('DROP INDEX IF EXISTS "INVENTORY".uq_inventory_stock_balances_grain');
        Schema::table('INVENTORY.stock_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->unique(['product_id', 'warehouse_id', 'location_id']);
        });

        Schema::table('INVENTORY.stock_valuation_layers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });

        Schema::table('INVENTORY.stock_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
        });
    }
};
