<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance module — §3H Forecast. A forecast links to exactly one of `budget_id` (revising
 * the outlook for an existing Budget) or `kpi_id` (standing alone against a KPI target) —
 * enforced in ForecastService, not a DB CHECK, same precedent as Accounting's
 * `FakturPajakService` (`ar_invoice_id` xor `ap_bill_id`, see that class's docblock): a
 * CHECK across two nullable FKs is portable here, but this codebase's established convention
 * is app-layer enforcement for this exact shape.
 *
 * Versioning ("revising a forecast creates a new version row rather than overwriting", §3H)
 * uses `root_forecast_id` (null on the first version, pointing to that first version's id on
 * every later one) + `version_no` instead of Budget's `prior_version_id` chain — versioning
 * here is strictly linear with no branching, so `version_no` alone fully orders a series once
 * grouped by `COALESCE(root_forecast_id, id)`; a separate prior-pointer would be redundant.
 * `is_latest` is a service-maintained denormalization (flipped old→false/new→true inside one
 * transaction in `ForecastService::revise()`), not a DB invariant — Postgres can't express
 * "exactly one is_latest per series" as a plain unique index once NULL `root_forecast_id`
 * (the root's own series-grouping value) is involved, same NULL-distinctness issue already
 * documented for `PERF.targets`/`PERF.kpi_values`/`PERF.budget_category_accounts`.
 *
 * `forecast_lines` has no `category` column, unlike `budget_lines` — a forecast is one
 * trajectory per header (either the linked Budget's total, or one KPI's), not per-category;
 * see VarianceService::evaluateForecastLine()'s docblock for how each link type resolves its
 * comparison value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PERF.forecast_hdrs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 30); // company | org_unit | employee
            $table->unsignedBigInteger('subject_id')->nullable(); // null only when subject_type = company
            $table->foreignId('budget_id')->nullable()->constrained('PERF.budget_hdrs');
            $table->foreignId('kpi_id')->nullable()->constrained('PERF.kpi_definitions');
            $table->foreignId('period_id')->constrained('PERF.periods'); // overall forecast horizon (year/quarter)
            $table->string('method', 20)->default('manual'); // manual only in MVP; reserved for future statistical methods
            $table->smallInteger('version_no')->default(1);
            $table->foreignId('root_forecast_id')->nullable()->constrained('PERF.forecast_hdrs');
            $table->boolean('is_latest')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['root_forecast_id', 'is_latest']);
        });

        Schema::create('PERF.forecast_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_id')->constrained('PERF.forecast_hdrs')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('PERF.periods'); // period slice, typically month
            $table->decimal('forecast_value', 18, 4);
            $table->timestamps();

            $table->unique(['forecast_id', 'period_id'], 'perf_forecast_lines_unique_slice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PERF.forecast_lines');
        Schema::dropIfExists('PERF.forecast_hdrs');
    }
};
