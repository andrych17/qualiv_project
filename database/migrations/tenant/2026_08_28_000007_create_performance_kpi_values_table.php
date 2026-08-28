<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance module — §3D KPI Actuals Capture. Same shape as §3C's `perf.targets` (one row
 * per kpi/subject/period, the identical polymorphic `subject_type`/`subject_id` seam), because
 * §3B's Budget Actuals fallback is explicitly documented to reuse this exact pattern later.
 * `source` is `manual` for every row in MVP — "reserved values for future connectors"
 * (PERFORMANCE_SPECS.md §3D) means a later automated feed writes into this same table with a
 * different `source` value, not a schema change.
 *
 * `KpiValueRecorded` (dispatched by KpiValueService) has no listener yet — the Variance
 * Analysis Engine (§3G) that would re-evaluate status and route a WNE notification isn't built.
 * Same "engine ships before caller" gap already documented elsewhere in this codebase (e.g.
 * Accounting's `accounting.journal_approval` workflow code, Adjustment's
 * `STATUS_PENDING_APPROVAL`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PERF.kpi_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_id')->constrained('PERF.kpi_definitions');
            $table->string('subject_type', 30); // company | org_unit | employee
            $table->unsignedBigInteger('subject_id')->nullable(); // null only when subject_type = company
            $table->foreignId('period_id')->constrained('PERF.periods');
            $table->decimal('actual_value', 18, 4);
            $table->string('source', 20)->default('manual');
            $table->foreignId('entered_by')->nullable()->constrained('users');
            $table->timestamp('entered_at');

            $table->unique(['kpi_id', 'subject_type', 'subject_id', 'period_id'], 'perf_kpi_values_unique_entry');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PERF.kpi_values');
    }
};
