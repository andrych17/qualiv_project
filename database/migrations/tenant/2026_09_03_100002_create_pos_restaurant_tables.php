<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS_SPECS.md §3M, §3N, §3O / §4 — Restaurant Extension Tables:
 * Floors, Tables, Modifier Groups, Modifiers, Product Modifiers, KDS Stations, and Routing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('POS.pos_floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('POS.pos_branches');
            $table->string('name', 100);
            $table->string('layout_ref', 255)->nullable();
        });

        Schema::create('POS.pos_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained('POS.pos_floors')->cascadeOnDelete();
            $table->string('code', 20);
            $table->integer('seat_count')->default(4);
            $table->integer('pos_x')->default(0);
            $table->integer('pos_y')->default(0);
            $table->string('status', 15)->default('available'); // available | occupied | reserved | cleaning

            $table->unique(['floor_id', 'code']);
        });

        Schema::create('POS.pos_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('selection_type', 10)->default('single'); // single | multiple
            $table->integer('min_selections')->default(0);
            $table->integer('max_selections')->default(1);
        });

        Schema::create('POS.pos_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('POS.pos_modifier_groups')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('price_delta', 14, 2)->default(0);
            $table->boolean('replaces_base_price')->default(false);

            $table->index('group_id');
        });

        Schema::create('POS.pos_product_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('group_id')->constrained('POS.pos_modifier_groups')->cascadeOnDelete();

            $table->unique(['product_id', 'group_id']);
        });

        Schema::create('POS.pos_kds_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('POS.pos_branches');
            $table->string('code', 30)->unique();
            $table->string('name', 100);
        });

        Schema::create('POS.pos_product_kds_routing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->foreignId('kds_station_id')->constrained('POS.pos_kds_stations');

            $table->unique(['product_id', 'kds_station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('POS.pos_product_kds_routing');
        Schema::dropIfExists('POS.pos_kds_stations');
        Schema::dropIfExists('POS.pos_product_modifier_groups');
        Schema::dropIfExists('POS.pos_modifiers');
        Schema::dropIfExists('POS.pos_modifier_groups');
        Schema::dropIfExists('POS.pos_tables');
        Schema::dropIfExists('POS.pos_floors');
    }
};
