<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance module — §3B Budgeting. `budget_hdrs.subject_type`/`subject_id` reuses the same
 * polymorphic seam as `PERF.targets`/`PERF.kpi_values` (§3C/§3D). `budget_lines.period_id` FKs
 * `PERF.periods` rather than storing a raw month — "reuses the same period model as
 * Budgeting/Forecast" was the §3C migration's own stated reason for building that table first.
 *
 * `prior_version_id`/`version_no` on `budget_hdrs` implement §3B's locking rule ("an approved
 * budget can be edited only by creating a new version — append-only history for audit") — a
 * self-referencing FK chain, same shape as DMS document versions.
 *
 * `budget_category_accounts` — tenant-editable category → GL account mapping, optionally
 * scoped to a company (`company_id` nullable). No DB-level uniqueness on
 * (category, account_id, company_id): Postgres treats every NULL `company_id` as distinct, so
 * a duplicate company-agnostic mapping wouldn't be caught by the index alone — same caveat as
 * `PERF.targets`/`PERF.kpi_values`, enforced in BudgetCategoryAccountService instead.
 *
 * `budget_actuals` deliberately drops the `period_id` column that `PERF.kpi_values` (§3D) has
 * — a budget line already pins exactly one period, so `unique(budget_line_id)` is the
 * meaningful constraint; carrying a redundant period_id would just be a second place for that
 * fact to go stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PERF.budget_hdrs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('subject_type', 30); // company | org_unit | employee
            $table->unsignedBigInteger('subject_id')->nullable(); // null only when subject_type = company
            $table->smallInteger('fiscal_year');
            $table->tinyInteger('fiscal_quarter')->nullable(); // null when the budget spans the whole year
            $table->string('status', 20)->default('draft'); // draft | submitted | approved | locked
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->smallInteger('version_no')->default(1);
            $table->foreignId('prior_version_id')->nullable()->constrained('PERF.budget_hdrs');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['fiscal_year', 'status']);
        });

        Schema::create('PERF.budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('PERF.budget_hdrs')->cascadeOnDelete();
            $table->string('category', 100); // free lookup, e.g. "Payroll", "Marketing"
            $table->foreignId('period_id')->constrained('PERF.periods');
            $table->decimal('amount_planned', 18, 4);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['budget_id', 'category', 'period_id'], 'perf_budget_lines_unique_slice');
        });

        Schema::create('PERF.budget_category_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('category', 100);
            $table->foreignId('account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('company_id')->nullable()->constrained('ACCOUNTING.companies');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('PERF.budget_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->unique()->constrained('PERF.budget_lines')->cascadeOnDelete();
            $table->decimal('actual_value', 18, 4);
            $table->string('source', 20)->default('manual'); // manual only in MVP; reserved for future connectors, same as kpi_values.source
            $table->foreignId('entered_by')->nullable()->constrained('users');
            $table->timestamp('entered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PERF.budget_actuals');
        Schema::dropIfExists('PERF.budget_category_accounts');
        Schema::dropIfExists('PERF.budget_lines');
        Schema::dropIfExists('PERF.budget_hdrs');
    }
};
