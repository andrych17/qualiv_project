<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Warehouse & Location Management (INVENTORY_SPECS.md §3C). Schema
 * INVENTORY already exists per tenant provisioning (App\Jobs\CreateModuleSchemas).
 *
 * `location_barcodes` (§3K) and `putaway_rules` (§3R, Operational) are deliberately not
 * created here — both are later builds per INVENTORY_SPECS.md's own suggested build order
 * (barcode wiring and Operational tier come after the MVP ledger engine), even though §4
 * lists them under the same schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.warehouses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('INVENTORY.locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('parent_location_id')->nullable()->constrained('INVENTORY.locations');
            $table->string('code', 30);
            // zone | bin | staging | dock — extensible list (§3C), not a DB enum: adding a new
            // type later is a code change (Request `in:` rule), never a migration.
            $table->string('type', 20)->default('bin');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['warehouse_id', 'code']);
            $table->index(['warehouse_id', 'parent_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.locations');
        Schema::dropIfExists('INVENTORY.warehouses');
    }
};
