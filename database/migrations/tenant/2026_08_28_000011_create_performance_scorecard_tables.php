<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance module — §3F Scorecard Builder & Viewer. "A Scorecard is a view/composition over
 * existing KPI and OKR data — it does not duplicate values, only weights and layout" (§3F Rules
 * / logic) — so this schema stores no computed actual/target/score/status columns at all; those
 * are always computed on read by ScorecardScoringService, same "computed on read, not stored"
 * posture §3E's Objective progress already established.
 *
 * `scorecard_items` links to exactly one of `kpi_id` or `okr_id` — two nullable typed FKs with
 * app-layer XOR enforcement (`ScorecardService::assertXor()`), the same pattern §3H's Forecast
 * already proved for budget_id/kpi_id, in preference to a generic `ref_type`/`ref_id` pair.
 *
 * No `perspective_set` column on the header: the set in use is always exactly whatever
 * perspectives its items reference — storing it separately would just be a second place for
 * that fact to drift out of sync, same reasoning as dropping `budget_actuals.period_id` (§3B).
 *
 * A Scorecard's `period_id` (PERF.periods) and an OKR item's own `cycle_id` (PERF.okr_cycles)
 * are unrelated and never cross-checked — an OKR's progress is self-contained from its own Key
 * Results, so no period/cycle alignment is needed for it to score correctly here. Not a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PERF.scorecard_hdrs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('subject_type', 30); // company | org_unit | employee
            $table->unsignedBigInteger('subject_id')->nullable(); // null only when subject_type = company
            $table->foreignId('period_id')->constrained('PERF.periods');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('PERF.scorecard_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scorecard_id')->constrained('PERF.scorecard_hdrs')->cascadeOnDelete();
            $table->foreignId('perspective_id')->constrained('PERF.perspectives');
            $table->foreignId('kpi_id')->nullable()->constrained('PERF.kpi_definitions');
            $table->foreignId('okr_id')->nullable()->constrained('PERF.okr_objectives');
            $table->decimal('weight', 5, 2); // percentage points within its perspective; must sum to 100 per (scorecard_id, perspective_id)
            $table->timestamps();

            $table->index(['scorecard_id', 'perspective_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PERF.scorecard_items');
        Schema::dropIfExists('PERF.scorecard_hdrs');
    }
};
