<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module — Product Master (INVENTORY_SPECS.md §3B) and its supporting lookup
 * tables. Schema INVENTORY already exists per tenant provisioning
 * (App\Jobs\CreateModuleSchemas). Scope: only what §3B needs — Warehouse/Location (§3C),
 * the stock ledger/valuation engine (§3D-§3J), and Barcode-as-scan-input wiring (§3K) are
 * later builds, per INVENTORY_SPECS.md's own suggested build order.
 *
 * These tables are unrelated to the legacy `public.inventory_items`/`inventory_categories`
 * pair (CLAUDE.md §7A "legacy demo tables") — no FK/reuse between the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('INVENTORY.product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_category_id')->nullable()->constrained('INVENTORY.product_categories');
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_category_id', 'is_active']);
        });

        Schema::create('INVENTORY.uoms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('INVENTORY.products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('INVENTORY.product_categories');
            $table->foreignId('base_uom_id')->constrained('INVENTORY.uoms');
            $table->string('costing_method', 10)->default('fifo'); // fifo | average — app-validated (Request rule)
            $table->decimal('reorder_point', 18, 4)->default(0);
            $table->decimal('reorder_quantity', 18, 4)->default(0);
            // none | batch | serial — Operational (§3L/§3M), present in schema from day one
            // per §3B, unenforced until those ship (MVP scope boundary, §5 Technical Notes).
            $table->string('tracking_mode', 10)->default('none');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category_id');
            $table->index('is_active');
        });

        Schema::create('INVENTORY.uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products')->cascadeOnDelete();
            $table->foreignId('uom_id')->constrained('INVENTORY.uoms');
            $table->decimal('conversion_factor', 18, 6); // 1 uom_id = conversion_factor x base_uom_id

            $table->unique(['product_id', 'uom_id']);
        });

        Schema::create('INVENTORY.product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products')->cascadeOnDelete();
            $table->string('barcode', 64)->unique(); // unique per tenant, per §3B rule
            $table->string('type', 20)->default('primary'); // primary | case_pack | alternate
            $table->decimal('unit_multiplier', 18, 6)->default(1); // e.g. case-pack barcode = x24 base UoM
        });

        // Partial unique index — Blueprint has no `->where()` for indexes (same reason
        // CRM_SPECS's addresses/contact_points primary-flag indexes are raw statements).
        DB::statement('CREATE UNIQUE INDEX uq_inventory_product_barcodes_primary ON "INVENTORY".product_barcodes (product_id) WHERE type = \'primary\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('INVENTORY.product_barcodes');
        Schema::dropIfExists('INVENTORY.uom_conversions');
        Schema::dropIfExists('INVENTORY.products');
        Schema::dropIfExists('INVENTORY.uoms');
        Schema::dropIfExists('INVENTORY.product_categories');
    }
};
