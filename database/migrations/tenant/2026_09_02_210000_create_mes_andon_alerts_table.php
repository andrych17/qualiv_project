<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3R — Alerts & Andon (Phase 3, built now per explicit user override — see
 * MES_SPECS.md §2 note on this migration's own docblock intent, and the session's own summary
 * of that decision).
 *
 * The Andon *state* (running/attention/stopped/maintenance) stays a pure read model over
 * `mes_machines.status` + open downtime/QC holds, exactly as the spec says — no state is stored
 * here. This table exists only for alert *delivery* bookkeeping: a computed condition (e.g.
 * "order X is behind schedule") has no natural row to attach a once-only `notified_at` guard to,
 * so `AndonService::checkAndFireAlerts()` needs its own anchor to avoid re-notifying WNE every
 * five minutes for a condition that's still true. The partial unique index is what makes
 * double-open impossible at the DB level, same shape as `idx_mes_qc_holds_open` /
 * `idx_mes_downtime_events_open`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_andon_alerts', function (Blueprint $table) {
            $table->id();
            // machine_stopped | maintenance_required | material_shortage | out_of_spec_parameter | behind_schedule | overdue_batch
            $table->string('alert_type', 30);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('severity', 10); // warning | critical
            $table->text('message');
            $table->timestampTz('fired_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('alert_type');
        });

        DB::statement('ALTER TABLE "MES".mes_andon_alerts ADD CONSTRAINT chk_mes_andon_alerts_type CHECK (alert_type IN (\'machine_stopped\', \'maintenance_required\', \'material_shortage\', \'out_of_spec_parameter\', \'behind_schedule\', \'overdue_batch\'))');
        DB::statement('ALTER TABLE "MES".mes_andon_alerts ADD CONSTRAINT chk_mes_andon_alerts_severity CHECK (severity IN (\'warning\', \'critical\'))');
        DB::statement('CREATE UNIQUE INDEX idx_mes_andon_alerts_open ON "MES".mes_andon_alerts (alert_type, subject_type, subject_id) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_andon_alerts');
    }
};
