<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3F / §4 — Process Phases & Parameters (process). `recipe_id` is a REAL
 * cross-schema FK into PP.pp_recipes (§3B boundary note; BOM/Recipe itself lives in PP, not
 * here). Per MES_SPECS.sql's DDL for this section. PP is sequenced immediately before MES
 * (CLAUDE.md §5), so PP.pp_recipes must already exist when this migration runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_process_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('PP.pp_recipes');
            $table->integer('seq');
            $table->string('phase_name', 150);
            $table->foreignId('work_center_id')->nullable()->constrained('MES.mes_work_centers');
            $table->integer('standard_duration_minutes')->nullable();

            $table->unique(['recipe_id', 'seq']);
        });

        Schema::create('MES.mes_process_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_phase_id')->constrained('MES.mes_process_phases')->cascadeOnDelete();
            $table->string('parameter_code', 50);
            $table->decimal('target_value', 18, 4)->nullable();
            $table->decimal('min_value', 18, 4)->nullable();
            $table->decimal('max_value', 18, 4)->nullable();
            $table->string('uom_code', 10)->nullable();

            $table->index('process_phase_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_process_parameters');
        Schema::dropIfExists('MES.mes_process_phases');
    }
};
