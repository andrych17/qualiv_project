<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3C / §4 — Production Event Ledger, append-only. Every later execution engine
 * (§3G–§3M) writes here; nothing else recomputes history independently. `batch_id` stays a
 * plain nullable BIGINT (no FK yet) — `mes_batches` doesn't exist until §3I is built, same
 * "FK added after mes_batches exists" deferral MES_SPECS.sql's own DDL documents.
 * `operation_ref` is genuinely polymorphic (mes_routing_ops.id or the future
 * mes_batch_phases.id, depending on event) and stays unconstrained by design, same posture
 * `pp_planned_orders.source_type`/`source_id` already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_prod_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('MES.mes_prod_order_hdrs');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('operation_ref')->nullable();
            $table->string('event_type', 30);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('machine_id')->nullable()->constrained('MES.mes_machines');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE "MES".mes_prod_events ADD CONSTRAINT chk_mes_prod_events_event_type CHECK (event_type IN (
                'order_released', 'material_issued', 'material_returned',
                'operation_started', 'operation_paused', 'operation_completed',
                'machine_started', 'machine_stopped', 'parameter_recorded',
                'qc_sample_taken', 'scrap_recorded', 'output_produced',
                'downtime_started', 'downtime_ended', 'batch_split', 'batch_merged'
            ))
        SQL);

        Schema::table('MES.mes_prod_events', function (Blueprint $table) {
            $table->index(['order_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_prod_events');
    }
};
