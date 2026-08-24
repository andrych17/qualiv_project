<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3J Budgeting — one flat annual budget per company/fiscal year (no revision/scenario
 * versioning in v1, per spec) holding account × cost center × period (monthly) amounts.
 *
 * budget_lines.amount is stored on the SAME normalized footing BudgetVsActualService reads
 * actuals on (AccountBalanceService/AllocationRunService convention): positive = the
 * expected side of the account's own normal_balance. A 5,000,000 budget line on an expense
 * account means "5,000,000 of debit-side activity," not a raw debit or credit figure —
 * variance is then always `actual - budget` with no sign gymnastics per row.
 *
 * No composite unique constraint on budget_lines (account_id, cost_center_id,
 * fiscal_period_id): cost_center_id is nullable, and Postgres treats every NULL as
 * distinct from every other NULL under a unique index, so a composite unique here would
 * silently fail to prevent duplicate "no cost center" cells for the same account/period —
 * worse than no constraint at all, since it would look like protection without providing
 * it. The no-duplicate-cell invariant instead lives in BudgetService::saveGrid()'s
 * replace-scope (delete every line for this budget+cost-center scope, then re-insert what
 * was submitted) — same "line set is a service invariant, not a DB one" precedent already
 * used for gl_journal_lines/ar_invoice_lines elsewhere in this module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.budgets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('fiscal_year_id')->constrained('ACCOUNTING.fiscal_years');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id']);
        });

        Schema::create('ACCOUNTING.budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('ACCOUNTING.budgets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('ACCOUNTING.cost_centers');
            $table->foreignId('fiscal_period_id')->constrained('ACCOUNTING.fiscal_periods');
            $table->decimal('amount', 18, 2);

            $table->index(['budget_id', 'cost_center_id']);
            $table->index(['budget_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.budget_lines');
        Schema::dropIfExists('ACCOUNTING.budgets');
    }
};
