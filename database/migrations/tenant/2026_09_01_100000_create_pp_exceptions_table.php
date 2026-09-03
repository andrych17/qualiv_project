<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PP_SPECS.md §3M — Planning Exception Center. Read model only: rows are written by each
 * engine's own constraint checks (§3F capacity overload, §3D/§3L material/schedule checks),
 * never created by hand. Matches PP_SPECS.sql's DDL for this table exactly (column set, CHECK
 * lists, partial index) — that file is this table's authoritative schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PP.pp_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('exception_type', 25);
            // material_shortage|capacity_overload|late_order|missing_routing|
            // resource_unavailable|maintenance_conflict|late_purchase
            $table->string('severity', 10)->default('medium'); // low|medium|high|critical
            $table->string('subject_type', 50); // e.g. 'pp.pp_planned_orders', 'pp.pp_capacity_plans'
            $table->unsignedBigInteger('subject_id');
            $table->text('detail')->nullable();
            $table->timestampTz('detected_at')->useCurrent();
            $table->string('status', 15)->default('open'); // open|acknowledged|resolved
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
        });

        DB::statement("ALTER TABLE \"PP\".pp_exceptions ADD CONSTRAINT chk_pp_exceptions_type CHECK (exception_type IN ('material_shortage', 'capacity_overload', 'late_order', 'missing_routing', 'resource_unavailable', 'maintenance_conflict', 'late_purchase'))");
        DB::statement("ALTER TABLE \"PP\".pp_exceptions ADD CONSTRAINT chk_pp_exceptions_severity CHECK (severity IN ('low', 'medium', 'high', 'critical'))");
        DB::statement("ALTER TABLE \"PP\".pp_exceptions ADD CONSTRAINT chk_pp_exceptions_status CHECK (status IN ('open', 'acknowledged', 'resolved'))");
        DB::statement('CREATE INDEX idx_pp_exceptions_open ON "PP".pp_exceptions (status, severity) WHERE status <> \'resolved\'');
        DB::statement('CREATE INDEX idx_pp_exceptions_subject ON "PP".pp_exceptions (subject_type, subject_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('PP.pp_exceptions');
    }
};
