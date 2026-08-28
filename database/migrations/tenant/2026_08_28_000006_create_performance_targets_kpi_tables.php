<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance module — §3C Targets & KPI Setup, the module's first buildable slice. Also lays
 * down `PERF.periods` and `PERF.perspectives`, the two shared master tables §3C's own tables
 * depend on ("Reuses the same period model as Budgeting/Forecast" — PERFORMANCE_SPECS.md §3C).
 * Budgeting/Forecast/OKR/Scorecard/Achievements (§3B, §3E–§3I) are separate, not-yet-built
 * slices — this migration creates only what §3C itself needs.
 *
 * `targets.subject_type`/`subject_id` is the standard polymorphic seam already used by
 * WNE/DMS/CRM/Schedule/Inventory — "multi-level" (company/department/team/individual/a
 * vertical record) is achieved by tagging the subject, never a separate hierarchy table
 * (PERFORMANCE_SPECS.md §1/§3C). `subject_id` is nullable to represent the tenant-wide
 * "Company" level, which has no backing record of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PERF.perspectives', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('PERF.periods', function (Blueprint $table) {
            $table->id();
            $table->string('label', 30)->unique(); // e.g. "2026", "2026-Q3", "2026-08"
            $table->string('period_type', 10); // year | quarter | month
            $table->smallInteger('year');
            $table->tinyInteger('quarter')->nullable();
            $table->tinyInteger('month')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['period_type', 'year']);
        });

        Schema::create('PERF.kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('unit', 10); // number | percent | currency | ratio
            $table->string('direction', 20); // higher_is_better | lower_is_better
            $table->foreignId('perspective_id')->nullable()->constrained('PERF.perspectives');
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('PERF.targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kpi_id')->constrained('PERF.kpi_definitions');
            $table->string('subject_type', 30); // company | org_unit | employee
            $table->unsignedBigInteger('subject_id')->nullable(); // null only when subject_type = company
            $table->foreignId('period_id')->constrained('PERF.periods');
            $table->decimal('target_value', 18, 4);
            $table->decimal('stretch_value', 18, 4)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['kpi_id', 'subject_type', 'subject_id', 'period_id'], 'perf_targets_unique_assignment');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PERF.targets');
        Schema::dropIfExists('PERF.kpi_definitions');
        Schema::dropIfExists('PERF.periods');
        Schema::dropIfExists('PERF.perspectives');
    }
};
