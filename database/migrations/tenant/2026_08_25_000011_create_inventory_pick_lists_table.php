<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Picking (§3O, Operational). A pick list is generated from one or more
 * active reservations (§3N) — every line traces back to exactly one, so a picked line can
 * `ReservationService::fulfill()` its reservation directly rather than re-deriving anything.
 * `location_id` is resolved at generation time even for a reservation that was "unassigned,
 * pending pick" — that's exactly the moment "which bin" gets decided (see
 * `PickListService::resolvePickLocation()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.pick_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->string('status', 20)->default('pending');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('INVENTORY.pick_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pick_list_id')->constrained('INVENTORY.pick_lists')->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained('INVENTORY.stock_reservations');
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('batch_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->foreignId('serial_id')->nullable()->constrained('INVENTORY.stock_serials');
            $table->foreignId('location_id')->constrained('INVENTORY.locations');
            $table->decimal('qty', 18, 4);
            $table->decimal('confirmed_qty', 18, 4)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('picked_at')->nullable();
            $table->foreignId('picked_by')->nullable()->constrained('users');

            $table->index(['pick_list_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.pick_list_lines');
        Schema::dropIfExists('INVENTORY.pick_lists');
    }
};
