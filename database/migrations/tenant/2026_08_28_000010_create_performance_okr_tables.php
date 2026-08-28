<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance module — §3E OKR Management. `okr_objectives.subject_type` uses the same
 * company/org_unit/employee triple as every other Performance table, not spec's looser prose
 * ("company/department/team/individual") — `org_unit` (HCM's self-referencing tree) already
 * covers department/team/division uniformly, per §3C's own docblock; "individual" = employee.
 *
 * `perf.okr_cycles` is deliberately its own minimal table (label/start/end/is_active), not a
 * reuse of `PERF.periods` — §3E names it explicitly as a distinct concept, and it needs none of
 * `PERF.periods`' period_type/year/quarter/month machinery.
 *
 * `parent_okr_id` is nullable self-FK with `nullOnDelete()` — deleting an Objective promotes
 * its children to top-level rather than cascading their deletion or blocking the delete; see
 * OkrObjectiveService for the cycle-guard that keeps this chain a tree, never a loop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PERF.okr_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('label', 30)->unique(); // e.g. "2026 Q3"
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('PERF.okr_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('PERF.okr_cycles');
            $table->string('subject_type', 30); // company | org_unit | employee
            $table->unsignedBigInteger('subject_id')->nullable(); // null only when subject_type = company
            $table->string('objective_text', 500);
            $table->foreignId('parent_okr_id')->nullable()->constrained('PERF.okr_objectives')->nullOnDelete();
            $table->string('status', 20)->default('on_track'); // on_track | at_risk | off_track | completed
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['cycle_id', 'status']);
        });

        Schema::create('PERF.okr_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('okr_id')->constrained('PERF.okr_objectives')->cascadeOnDelete();
            $table->string('description', 255);
            $table->string('metric_type', 20); // numeric | percent | boolean | milestone
            $table->decimal('start_value', 18, 4)->default(0);
            $table->decimal('current_value', 18, 4)->default(0);
            $table->decimal('target_value', 18, 4);
            $table->decimal('weight', 5, 2)->default(100); // relative weight within its Objective's weighted-average progress
            $table->timestamps();

            $table->index('okr_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PERF.okr_key_results');
        Schema::dropIfExists('PERF.okr_objectives');
        Schema::dropIfExists('PERF.okr_cycles');
    }
};
