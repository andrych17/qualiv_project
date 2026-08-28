<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance module — §3I Achievements Engine, the last Performance section: the "recent
 * Achievements feed" §3A's own Dashboard already reserved an explicit not-yet-available slot
 * for (see DashboardController's docblock) is now real.
 *
 * `achievements` references "the KPI/OKR/period that triggered it" via two nullable typed FKs
 * (`kpi_id`/`okr_id`) rather than a generic `ref_type`/`ref_id` pair — same precedent §3H
 * Forecast and §3F Scorecard already established for this exact "points at exactly one of two
 * sibling tables" shape, and both tables live in this same schema so a real FK is safe here
 * (unlike `budget_category_accounts.account_id`, which is deliberately non-enforced because
 * Accounting is an optional install). `period_id` is nullable because an `okr_completed`
 * achievement has no period — OKRs are cycle-scoped, not period-scoped (see §3E's own
 * `okr_cycles` migration docblock).
 *
 * No DB uniqueness on (badge_id, subject_type, subject_id, kpi_id/okr_id, period_id) — every
 * nullable column in that tuple hits the same Postgres NULL-distinctness issue already
 * documented for `PERF.targets`/`kpi_values`/`budget_category_accounts`; de-duplication is an
 * app-layer check in AchievementService instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PERF.badge_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('trigger_type', 30); // target_hit | okr_completed | streak_on_track
            $table->json('trigger_params')->nullable(); // e.g. {"streak_length": 3} for streak_on_track
            $table->string('icon', 50)->nullable(); // lucide-vue-next icon name
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('PERF.achievements', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 30); // company | org_unit | employee
            $table->unsignedBigInteger('subject_id')->nullable(); // null only when subject_type = company
            $table->foreignId('badge_id')->constrained('PERF.badge_definitions');
            $table->foreignId('kpi_id')->nullable()->constrained('PERF.kpi_definitions');
            $table->foreignId('okr_id')->nullable()->constrained('PERF.okr_objectives');
            $table->foreignId('period_id')->nullable()->constrained('PERF.periods');
            $table->timestamp('earned_at');
            $table->foreignId('awarded_by')->nullable()->constrained('users'); // null = system auto-award

            $table->index(['subject_type', 'subject_id']);
            $table->index('badge_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PERF.achievements');
        Schema::dropIfExists('PERF.badge_definitions');
    }
};
