<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3I Cost Accounting — cost centers themselves already shipped with §3B
 * (ACCOUNTING.cost_centers, attachable on gl_journal_lines). The only new piece here is
 * allocation runs: percentage-based redistribution of a source account/cost-center pool
 * to target cost centers, via a same-account journal (debit each target, credit the
 * source) so the account's total balance never moves — only its cost-center attribution
 * does. source_cost_center_id nullable = "lines with no cost center at all" (the
 * unallocated pool) — this is currently the ONLY pool AR/AP-originated expense lines can
 * ever land in, since ar_invoice_lines/ap_bill_lines don't carry cost_center_id yet
 * (§3I's own spec text calls for it on "any journal/AR/AP/asset line," but retrofitting
 * two already-shipped subledger engines is out of this pass's scope — deliberately
 * deferred, not an oversight).
 *
 * allocation_runs.unique(allocation_rule_id, fiscal_period_id) is the idempotency guard,
 * same discipline as §3G's depreciation schedule and §3P's recurring_generation_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.allocation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('name', 100);
            $table->foreignId('source_account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('source_cost_center_id')->nullable()->constrained('ACCOUNTING.cost_centers');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('ACCOUNTING.allocation_rule_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_rule_id')->constrained('ACCOUNTING.allocation_rules')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('ACCOUNTING.cost_centers');
            $table->decimal('percentage', 5, 2); // all targets on a rule must sum to exactly 100.00 (app-validated at save)

            $table->unique(['allocation_rule_id', 'cost_center_id']);
        });

        Schema::create('ACCOUNTING.allocation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allocation_rule_id')->constrained('ACCOUNTING.allocation_rules');
            $table->foreignId('fiscal_period_id')->constrained('ACCOUNTING.fiscal_periods');
            $table->decimal('source_amount', 18, 2);
            $table->foreignId('journal_id')->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['allocation_rule_id', 'fiscal_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.allocation_runs');
        Schema::dropIfExists('ACCOUNTING.allocation_rule_targets');
        Schema::dropIfExists('ACCOUNTING.allocation_rules');
    }
};
