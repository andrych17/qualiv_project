<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3I / §4 — Process Execution: Batch / Phase. `recipe_id` is a REAL cross-schema
 * FK into PP.pp_recipes (§3B). `mes_batch_phases.process_phase_id` FKs into `MES.mes_process_phases`
 * (§3F, already built). Per MES_SPECS.sql's DDL for this section — `mes_batch_relations`
 * (split/merge) is created here too (§4 lists it under this section) but has no UI in this
 * build; it feeds Traceability (§3K), not yet built, which is the natural place to add the
 * split/merge screens once there's something to trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('MES.mes_prod_order_hdrs');
            $table->string('batch_number', 30)->unique();
            $table->foreignId('recipe_id')->constrained('PP.pp_recipes');
            $table->string('status', 15)->default('draft'); // draft | running | paused | completed | cancelled
            $table->decimal('planned_qty', 18, 4);
            $table->decimal('actual_yield_pct', 5, 2)->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        DB::statement('ALTER TABLE "MES".mes_batches ADD CONSTRAINT chk_mes_batches_status CHECK (status IN (\'draft\', \'running\', \'paused\', \'completed\', \'cancelled\'))');

        Schema::create('MES.mes_batch_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('MES.mes_batches')->cascadeOnDelete();
            $table->foreignId('raw_material_product_id')->constrained('INVENTORY.products');
            $table->decimal('resolved_qty', 18, 6); // scaled at batch creation — bypasses the not-yet-built PpService::scaleRecipe() (§3B/§7); see BatchExecutionService
            $table->string('uom_code', 10)->nullable();

            $table->index('batch_id');
        });

        Schema::create('MES.mes_batch_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('MES.mes_batches')->cascadeOnDelete();
            $table->foreignId('process_phase_id')->constrained('MES.mes_process_phases');
            $table->integer('seq');
            $table->string('status', 15)->default('pending'); // pending | running | paused | completed
            $table->timestampTz('start_at')->nullable();
            $table->timestampTz('end_at')->nullable();
            $table->unsignedBigInteger('operator_id')->nullable(); // informational; HCM.employees.id (HCM plan-optional relative to MES)
            $table->foreignId('machine_id')->nullable()->constrained('MES.mes_machines');

            $table->unique(['batch_id', 'seq']);
        });

        DB::statement('ALTER TABLE "MES".mes_batch_phases ADD CONSTRAINT chk_mes_batch_phases_status CHECK (status IN (\'pending\', \'running\', \'paused\', \'completed\'))');

        Schema::create('MES.mes_batch_parameter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_phase_id')->constrained('MES.mes_batch_phases')->cascadeOnDelete();
            $table->foreignId('process_parameter_id')->constrained('MES.mes_process_parameters');
            $table->decimal('value', 18, 4);
            $table->timestampTz('recorded_at')->useCurrent();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->foreignId('machine_id')->nullable()->constrained('MES.mes_machines'); // nullable; set when IoT-sourced (§3S, Phase 3)

            $table->index('batch_phase_id');
        });

        Schema::create('MES.mes_batch_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_batch_id')->constrained('MES.mes_batches');
            $table->foreignId('child_batch_id')->constrained('MES.mes_batches');
            $table->string('relation_type', 10); // split | merge
            $table->decimal('qty', 18, 4);
        });

        DB::statement('ALTER TABLE "MES".mes_batch_relations ADD CONSTRAINT chk_mes_batch_relations_type CHECK (relation_type IN (\'split\', \'merge\'))');
        DB::statement('ALTER TABLE "MES".mes_batch_relations ADD CONSTRAINT chk_mes_batch_relations_distinct CHECK (parent_batch_id <> child_batch_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_batch_relations');
        Schema::dropIfExists('MES.mes_batch_parameter_readings');
        Schema::dropIfExists('MES.mes_batch_phases');
        Schema::dropIfExists('MES.mes_batch_ingredients');
        Schema::dropIfExists('MES.mes_batches');
    }
};
