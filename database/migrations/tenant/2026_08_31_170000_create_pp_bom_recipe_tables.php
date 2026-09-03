<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3D BOM/Recipe master data — PP's own (not read from MES), per PP_SPECS.sql's
 * DDL for this section. `uom_code` is informational (INVENTORY.uoms.code), not a real FK — the
 * component/ingredient's own base UoM is what actually governs quantity accounting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_boms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->integer('version')->default(1);
            $table->date('effective_from')->default(now()->toDateString());
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'version']);
        });

        // §3D "only one active BOM version per product" — partial unique index, same
        // technique as INVENTORY.products' single-primary-barcode rule.
        DB::statement('CREATE UNIQUE INDEX uq_pp_boms_one_active_per_product ON "PP".pp_boms (product_id) WHERE is_active');

        Schema::create('PP.pp_bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bom_id')->constrained('PP.pp_boms')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('INVENTORY.products');
            $table->decimal('qty_per_parent_unit', 18, 6);
            $table->string('uom_code', 10)->nullable();
            $table->decimal('scrap_pct', 5, 2)->default(0);

            $table->index('bom_id');
        });

        Schema::create('PP.pp_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('INVENTORY.products');
            $table->integer('version')->default(1);
            $table->decimal('batch_size', 18, 4);
            $table->string('uom_code', 10)->nullable();
            $table->decimal('expected_yield_pct', 5, 2)->default(100);
            $table->decimal('expected_waste_pct', 5, 2)->default(0);
            $table->date('effective_from')->default(now()->toDateString());
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'version']);
        });

        DB::statement('CREATE UNIQUE INDEX uq_pp_recipes_one_active_per_product ON "PP".pp_recipes (product_id) WHERE is_active');

        Schema::create('PP.pp_recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('PP.pp_recipes')->cascadeOnDelete();
            $table->foreignId('raw_material_product_id')->constrained('INVENTORY.products');
            $table->decimal('qty_per_batch', 18, 6);
            $table->string('uom_code', 10)->nullable();

            $table->index('recipe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_recipe_ingredients');
        Schema::dropIfExists('PP.pp_recipes');
        Schema::dropIfExists('PP.pp_bom_lines');
        Schema::dropIfExists('PP.pp_boms');
    }
};
