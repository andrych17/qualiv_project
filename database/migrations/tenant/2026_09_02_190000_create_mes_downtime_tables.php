<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3M / §4 — Equipment Status & Downtime (Phase 2).
 *
 * `mes_equipment_status_logs` is machine-only per the spec's own column list — no work-center
 * variant, since "current status" is a `mes_machines.status` concept (§3D), not a work-center
 * one. `mes_downtime_events` may instead be logged against a bare work center when no single
 * machine is the cause (e.g. a whole line down for a material shortage) — same
 * either-or-both ownership rule `mes_stations` already uses (`chk_mes_stations_owner`).
 *
 * `notified_at` is not in the spec's own column list — it's this build's idempotency guard for
 * the "past a configurable duration threshold, auto-creates a maintenance request" rule, so the
 * sweep (`DowntimeService::checkOpenThresholds()`) never double-fires the same open event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_equipment_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('MES.mes_machines');
            // running | idle | down | maintenance | setup | waiting_material | waiting_operator | waiting_qc — app-validated, mirrors mes_machines.status.
            $table->string('status', 20);
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('ended_at')->nullable();

            $table->index('machine_id');
        });

        DB::statement('CREATE INDEX idx_mes_equipment_status_logs_open ON "MES".mes_equipment_status_logs (machine_id) WHERE ended_at IS NULL');

        Schema::create('MES.mes_downtime_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->nullable()->constrained('MES.mes_machines');
            $table->foreignId('work_center_id')->nullable()->constrained('MES.mes_work_centers');
            $table->foreignId('order_id')->nullable()->constrained('MES.mes_prod_order_hdrs');
            $table->string('category', 15); // planned | unplanned
            // maintenance | setup (planned); mechanical | electrical | material_shortage | quality | operator (unplanned) — app-validated combo, see StoreDowntimeEventRequest.
            $table->string('reason_code', 30);
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users');
            $table->foreignId('ended_by')->nullable()->constrained('users');

            $table->index('machine_id');
            $table->index('work_center_id');
            $table->index('order_id');
        });

        DB::statement('ALTER TABLE "MES".mes_downtime_events ADD CONSTRAINT chk_mes_downtime_events_owner CHECK (machine_id IS NOT NULL OR work_center_id IS NOT NULL)');
        DB::statement('ALTER TABLE "MES".mes_downtime_events ADD CONSTRAINT chk_mes_downtime_events_category CHECK (category IN (\'planned\', \'unplanned\'))');
        DB::statement('CREATE INDEX idx_mes_downtime_events_open ON "MES".mes_downtime_events (started_at) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_downtime_events');
        Schema::dropIfExists('MES.mes_equipment_status_logs');
    }
};
