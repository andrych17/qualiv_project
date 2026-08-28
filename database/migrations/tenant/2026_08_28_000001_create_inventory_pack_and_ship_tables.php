<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Packing & Shipping (§3P, Operational). A `pack_list` is one physical
 * package (carton/pallet) built from one or more PICKED `pick_list_lines` of a single pick
 * list — a pick list line's picked qty can be split across several packages, so
 * `pack_list_lines` references the pick-list line rather than the reservation directly
 * (PackListService sums existing `pack_list_lines.qty` per `pick_list_line_id` to know what's
 * left to pack). `product_id`/`batch_id`/`serial_id` are denormalized onto the line, same
 * posture as `pick_list_lines`/`goods_issue_lines`, rather than joining back through the pick
 * list line every read.
 *
 * A `shipment` links one or more pack lists (a pack list belongs to at most one shipment —
 * `pack_lists.shipment_id`, not a pivot, since a physical package ships exactly once). Per
 * §3P: "Ship-confirm... triggers the actual Goods Issue (§3E)... the real
 * inventory-decrementing event" — `goods_issue_id` is nullable and only set at that moment
 * (ShipmentService::shipConfirm()); no ledger/balance columns live here, matching Picking's own
 * "workflow layer on top" posture (see PickListService's docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->string('carrier', 60)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->date('ship_date')->nullable();
            $table->string('status', 20)->default('pending'); // pending | shipped | delivered
            $table->foreignId('goods_issue_id')->nullable()->constrained('INVENTORY.goods_issues');
            $table->foreignId('shipped_by')->nullable()->constrained('users');
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('INVENTORY.pack_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('pick_list_id')->constrained('INVENTORY.pick_lists');
            $table->foreignId('shipment_id')->nullable()->constrained('INVENTORY.shipments');
            $table->string('package_type', 20)->default('carton'); // carton | pallet
            $table->decimal('weight', 12, 4)->nullable();
            $table->string('weight_uom', 10)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->string('dimension_uom', 10)->nullable();
            $table->string('status', 20)->default('packed'); // packed | shipped
            $table->foreignId('packed_by')->nullable()->constrained('users');
            $table->timestamp('packed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['pick_list_id']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('INVENTORY.pack_list_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_list_id')->constrained('INVENTORY.pack_lists')->cascadeOnDelete();
            $table->foreignId('pick_list_line_id')->constrained('INVENTORY.pick_list_lines');
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('batch_id')->nullable()->constrained('INVENTORY.stock_batches');
            $table->foreignId('serial_id')->nullable()->constrained('INVENTORY.stock_serials');
            $table->decimal('qty', 18, 4);

            $table->index(['pack_list_id']);
            $table->index(['pick_list_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.pack_list_lines');
        Schema::dropIfExists('INVENTORY.pack_lists');
        Schema::dropIfExists('INVENTORY.shipments');
    }
};
