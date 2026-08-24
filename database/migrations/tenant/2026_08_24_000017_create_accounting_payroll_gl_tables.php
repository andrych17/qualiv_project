<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3S Payroll GL Posting — the financial-side-only interface engine, same shape as §3H's
 * Inventory GL Posting: Payroll (`PAYROLL_SPECS.md`) remains the sole source of truth for
 * every statutory calculation; this only translates an already-finalized `payroll.run_paid`
 * event into a GL journal.
 *
 * Unlike §3H, `payroll_component_gl_mappings.component_code` has no candidate table to even
 * softly reference — the Payroll module has zero real code (only scaffolding), and its own
 * spec's `payroll_components.gl_account_placeholder` (PAYROLL_SPECS.md §3B) is explicitly
 * marked "for Future Version accounting export," i.e. not populated yet either. The mapping
 * is keyed by a plain string code (e.g. 'PPH21', 'BPJS_KESEHATAN_EE') that this module's own
 * admin UI defines — when Payroll's real engine ships, its `payroll_components.code` is the
 * join key this table is written to line up with.
 *
 * Two account columns per mapping row, not one: `gl_account_id` is always the expense
 * (earning/employer_cost) or payable (deduction) account for that component;
 * `payable_account_id` is ADDITIONALLY required for employer_cost rows only — an employer
 * contribution (e.g. Employer BPJS) both debits an expense (`gl_account_id`) AND credits a
 * payable (`payable_account_id`, typically the SAME payable account the matching employee
 * deduction row also credits, since the company remits one combined amount) — see
 * PayrollGlPostingService's docblock for the balancing arithmetic this makes work.
 *
 * `companies.payroll_net_pay_payable_account_id` is the same one-designated-control-account
 * pattern as `ar_control_account_id`/`ap_control_account_id`/`inventory_control_account_id`
 * — net pay is a single balancing figure per run, not a per-component mapping.
 *
 * payroll_gl_postings/payroll_posting_failures mirror inventory_gl_postings/
 * inventory_posting_failures exactly (idempotency via unique(subject_type, subject_id), same
 * "fails loudly and queues for review" discipline) — see that migration's docblock for the
 * full rationale, unchanged here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->foreignId('payroll_net_pay_payable_account_id')->nullable()->after('inventory_control_account_id')->constrained('ACCOUNTING.accounts');
        });

        Schema::create('ACCOUNTING.payroll_component_gl_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('component_code', 30);
            $table->string('component_label', 100);
            $table->string('component_type', 20); // earning|deduction|employer_cost
            $table->foreignId('gl_account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('payable_account_id')->nullable()->constrained('ACCOUNTING.accounts');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'component_code']);
        });

        Schema::create('ACCOUNTING.payroll_gl_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('subject_type', 60);
            $table->string('subject_id', 60);
            $table->foreignId('journal_id')->constrained('ACCOUNTING.gl_journals');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('ACCOUNTING.payroll_posting_failures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('subject_type', 60);
            $table->string('subject_id', 60);
            $table->json('payload'); // the original event's data, so Retry can replay it
            $table->string('reason', 255);
            $table->string('status', 20)->default('pending'); // pending|resolved
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');

            $table->unique(['subject_type', 'subject_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.payroll_posting_failures');
        Schema::dropIfExists('ACCOUNTING.payroll_gl_postings');
        Schema::dropIfExists('ACCOUNTING.payroll_component_gl_mappings');
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_net_pay_payable_account_id');
        });
    }
};
