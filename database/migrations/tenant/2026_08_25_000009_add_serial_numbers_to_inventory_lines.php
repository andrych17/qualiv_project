<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Serial Number Tracking wiring (§3M). A batch-tracked line carries a
 * single `batch_id` FK (§3L) because one lot covers the whole line; a serial-tracked line
 * covers N units, each its own identity, so a single FK column doesn't fit. `serial_numbers`
 * is a JSON scratch column — the draft-time working list of strings the line round-trips
 * through the UI — resolved into real `stock_serials` rows only at post() (same posture as
 * AdjustmentLine.system_qty: a convenience snapshot, not the authoritative source).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('INVENTORY.goods_receipt_lines', function (Blueprint $table) {
            $table->json('serial_numbers')->nullable()->after('batch_id');
        });

        Schema::table('INVENTORY.goods_issue_lines', function (Blueprint $table) {
            $table->json('serial_numbers')->nullable()->after('batch_id');
        });

        Schema::table('INVENTORY.transfer_lines', function (Blueprint $table) {
            $table->json('serial_numbers')->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('INVENTORY.transfer_lines', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });

        Schema::table('INVENTORY.goods_issue_lines', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });

        Schema::table('INVENTORY.goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });
    }
};
