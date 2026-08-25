<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Reservations (§3N, Operational). A soft hold against `stock_balances`
 * for a caller (Sales order-confirm, not built yet) that wants to promise stock without
 * moving it. `location_id` nullable = "unassigned, pending pick" (§3O) — still reduces
 * available-to-promise at every location in the warehouse, since it could be picked from any
 * of them. `batch_id`/`serial_id` are optional pins: a reservation against a batch-tracked
 * product doesn't have to name a specific lot (lot-agnostic promise is a valid MVP shape);
 * naming a `serial_id` forces the reservation to exactly that unit (qty is always 1 then).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('batch_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->foreignId('serial_id')->nullable()->constrained('INVENTORY.stock_serials');
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('location_id')->nullable()->constrained('INVENTORY.locations');
            $table->decimal('qty', 18, 4);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.stock_reservations');
    }
};
