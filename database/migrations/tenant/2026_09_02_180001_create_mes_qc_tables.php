<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3L / §4 — Quality Control, Phase 1 basic (inspection plans/characteristics,
 * samples/results, holds). Per MES_SPECS.sql's DDL for this section.
 *
 * `mes_qc_holds` is record-only in this build — no Inventory `quality_status`/held-vs-sellable
 * concept exists anywhere in the already-shipped Inventory module (`stock_batches`/
 * `stock_balances` were checked; neither has one), so a hold here doesn't block any Inventory
 * operation. It's a real, visible, releasable row — just not a physical stock gate. Making it a
 * real gate means extending Inventory itself (a different Core module) for a benefit only MES
 * uses today; out of scope for this section on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_qc_inspection_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('INVENTORY.products');
            $table->string('name', 150);
        });

        Schema::create('MES.mes_qc_characteristics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('MES.mes_qc_inspection_plans')->cascadeOnDelete();
            $table->string('characteristic_name', 150);
            $table->string('spec_type', 15)->default('numeric'); // numeric | pass_fail
            $table->decimal('target_value', 18, 4)->nullable();
            $table->decimal('min_value', 18, 4)->nullable();
            $table->decimal('max_value', 18, 4)->nullable();
            $table->string('uom_code', 10)->nullable();

            $table->index('plan_id');
        });

        DB::statement('ALTER TABLE "MES".mes_qc_characteristics ADD CONSTRAINT chk_mes_qc_characteristics_spec_type CHECK (spec_type IN (\'numeric\', \'pass_fail\'))');

        Schema::create('MES.mes_qc_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('MES.mes_prod_order_hdrs');
            $table->foreignId('batch_phase_id')->nullable()->constrained('MES.mes_batch_phases');
            $table->string('sample_number', 30)->unique();
            $table->foreignId('taken_by')->constrained('users');
            $table->timestampTz('taken_at')->useCurrent();
        });

        DB::statement('ALTER TABLE "MES".mes_qc_samples ADD CONSTRAINT chk_mes_qc_samples_subject CHECK (order_id IS NOT NULL OR batch_phase_id IS NOT NULL)');

        Schema::create('MES.mes_qc_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sample_id')->constrained('MES.mes_qc_samples')->cascadeOnDelete();
            $table->foreignId('characteristic_id')->constrained('MES.mes_qc_characteristics');
            $table->decimal('actual_value', 18, 4)->nullable();
            $table->string('result', 10); // pass | fail | hold
        });

        DB::statement('ALTER TABLE "MES".mes_qc_results ADD CONSTRAINT chk_mes_qc_results_result CHECK (result IN (\'pass\', \'fail\', \'hold\'))');

        Schema::create('MES.mes_qc_holds', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 50); // e.g. 'inventory.stock_batches', 'inventory.stock_serials', 'mes.mes_production_outputs'
            $table->unsignedBigInteger('subject_id');
            $table->text('reason')->nullable();
            $table->string('status', 10)->default('open'); // open | released
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->timestampTz('released_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement('ALTER TABLE "MES".mes_qc_holds ADD CONSTRAINT chk_mes_qc_holds_status CHECK (status IN (\'open\', \'released\'))');
        DB::statement('CREATE INDEX idx_mes_qc_holds_open ON "MES".mes_qc_holds (status) WHERE status = \'open\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_qc_holds');
        Schema::dropIfExists('MES.mes_qc_results');
        Schema::dropIfExists('MES.mes_qc_samples');
        Schema::dropIfExists('MES.mes_qc_characteristics');
        Schema::dropIfExists('MES.mes_qc_inspection_plans');
    }
};
