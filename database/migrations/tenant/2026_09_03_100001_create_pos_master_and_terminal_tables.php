<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS_SPECS.md §3A, §3B, §3E, §3Q / §4 — Master & Topology Tables:
 * Profiles, Branches, Terminals, Devices, Weighted Barcode Templates, and Favorite Items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('POS.pos_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('base_type', 15)->default('retail'); // retail | restaurant | service
            $table->boolean('requires_barcode')->default(true);
            $table->boolean('touch_menu')->default(false);
            $table->boolean('multi_uom')->default(true);
            $table->boolean('batch_expiry_tracking')->default(false);
            $table->boolean('weight_scale')->default(false);
            $table->boolean('customer_required')->default(false);
            $table->boolean('loyalty_enabled')->default(true);
            $table->boolean('promotion_enabled')->default(true);
            $table->boolean('table_management')->default(false);
            $table->boolean('modifiers_enabled')->default(false);
            $table->boolean('kds_enabled')->default(false);
            $table->boolean('recipe_consumption')->default(false);
            $table->boolean('delivery_enabled')->default(false);
            $table->boolean('offline_enabled')->default(true);
            $table->boolean('multi_branch')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('POS.pos_branches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('POS.pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('branch_id')->nullable()->constrained('POS.pos_branches');
            $table->foreignId('warehouse_id')->constrained('INVENTORY.warehouses');
            $table->foreignId('profile_id')->constrained('POS.pos_profiles');
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->unsignedBigInteger('default_price_list_id')->nullable(); // Informational (SALES.price_lists)
            $table->string('default_tax_code', 20)->nullable();
            $table->string('receipt_template', 50)->nullable();
            $table->string('receipt_prefix', 10)->unique();
            $table->unsignedBigInteger('last_local_seq')->default(0);
            $table->string('device_fingerprint', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index('warehouse_id');
        });

        Schema::create('POS.pos_terminal_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained('POS.pos_terminals')->cascadeOnDelete();
            $table->string('device_type', 20); // receipt_printer | kitchen_printer | cash_drawer | customer_display | weighing_scale | card_terminal
            $table->string('adapter_code', 50);
            $table->jsonb('connection_config')->nullable();
            $table->boolean('is_active')->default(true);

            $table->index('terminal_id');
        });

        Schema::create('POS.pos_weighted_barcode_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('prefix_from', 4);
            $table->string('prefix_to', 4);
            $table->smallInteger('item_code_start');
            $table->smallInteger('item_code_length');
            $table->smallInteger('value_start');
            $table->smallInteger('value_length');
            $table->string('value_type', 10); // weight | price
            $table->smallInteger('decimal_places')->default(3);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('POS.pos_favorite_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->nullable()->constrained('POS.pos_terminals')->cascadeOnDelete();
            $table->foreignId('cashier_user_id')->nullable()->constrained('users');
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->integer('sort_order')->default(0);

            $table->index('terminal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('POS.pos_favorite_items');
        Schema::dropIfExists('POS.pos_weighted_barcode_templates');
        Schema::dropIfExists('POS.pos_terminal_devices');
        Schema::dropIfExists('POS.pos_terminals');
        Schema::dropIfExists('POS.pos_branches');
        Schema::dropIfExists('POS.pos_profiles');
    }
};
