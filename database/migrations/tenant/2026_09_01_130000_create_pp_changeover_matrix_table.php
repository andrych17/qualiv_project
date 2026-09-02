<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3J — Setup & Changeover Matrix, per PP_SPECS.sql's own DDL for this section
 * (verbatim column set: from/to at either product or family granularity, `resource_group_id`
 * nullable — null means "applies across every resource group"). `from_family`/`to_family` are
 * free-text tags resolved against `INVENTORY.product_categories.name` at lookup time
 * (`ChangeoverMatrixService`) — no new master data invented, same posture as §3I's
 * DemandHeader/SalesOrder reuse. `'other'` is a literal wildcard family value (see PP_SPECS.sql
 * §5's own seed example), not a real category — the lookup treats it as a catch-all fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_changeover_matrix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_product_id')->nullable()->constrained('INVENTORY.products')->nullOnDelete();
            $table->string('from_family', 100)->nullable();
            $table->foreignId('to_product_id')->nullable()->constrained('INVENTORY.products')->nullOnDelete();
            $table->string('to_family', 100)->nullable();
            $table->foreignId('resource_group_id')->nullable()->constrained('PP.pp_resource_groups')->nullOnDelete();
            $table->integer('changeover_minutes')->default(0);
            $table->integer('cleaning_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['resource_group_id', 'from_product_id', 'to_product_id'], 'idx_pp_changeover_matrix_lookup');
        });

        DB::statement('ALTER TABLE "PP".pp_changeover_matrix ADD CONSTRAINT chk_pp_changeover_matrix_from CHECK (from_product_id IS NOT NULL OR from_family IS NOT NULL)');
        DB::statement('ALTER TABLE "PP".pp_changeover_matrix ADD CONSTRAINT chk_pp_changeover_matrix_to CHECK (to_product_id IS NOT NULL OR to_family IS NOT NULL)');
        DB::statement('ALTER TABLE "PP".pp_changeover_matrix ADD CONSTRAINT chk_pp_changeover_matrix_minutes CHECK (changeover_minutes >= 0 AND cleaning_minutes >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_changeover_matrix');
    }
};
