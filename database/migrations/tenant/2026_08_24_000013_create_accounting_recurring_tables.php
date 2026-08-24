<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3P Recurring Transactions — v1 ships recurring journals + recurring AR invoices.
 * Recurring AP bills are deliberately deferred: a vendor bill's bill_no is the
 * vendor's own reference, unknowable at template time, and there is no safe
 * placeholder that wouldn't risk reaching FakturPajakService::recordInput()'s
 * Faktur Pajak uniqueness check with a synthetic value. §3P's spec text names
 * "monthly office rent bill" as a motivating example, but nothing here forecloses
 * adding recurring_ap_templates later along the same shape as the AR pair below.
 *
 * recurring_journal_templates.source on the generated journal is 'recurring' —
 * already reserved (unused) on gl_journals.source since the §3C migration, same
 * "reserve now, wire up when the engine ships" convention §3G used for 'asset'.
 *
 * Scheduling columns (recurrence_rule/anchor_date/next_run_date/last_run_date/
 * is_active) are duplicated identically on both template tables rather than
 * factored into a shared table, since the two template types have nothing else
 * in common (their line shapes are entirely different) — RecurringGenerationService
 * is what actually shares the due-scan logic, not the schema.
 *
 * recurring_generation_log is the idempotency guard: unique(template_type,
 * template_id, run_date) means a double-fired cron (or a second manual run)
 * cannot generate the same occurrence twice, mirroring the DB-level uniqueness
 * discipline §3G's depreciation schedule tables use for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.recurring_journal_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('name', 100);
            $table->string('memo', 255)->nullable();
            $table->char('currency_code', 3);
            $table->string('recurrence_rule', 255);
            $table->date('anchor_date');
            $table->date('next_run_date')->nullable(); // null = exhausted (rule's own COUNT/UNTIL reached) or never started
            $table->date('last_run_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->index(['company_id', 'is_active', 'next_run_date']);
        });

        Schema::create('ACCOUNTING.recurring_journal_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_journal_template_id')->constrained('ACCOUNTING.recurring_journal_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('ACCOUNTING.cost_centers');
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('description', 255)->nullable();
        });

        Schema::create('ACCOUNTING.recurring_ar_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->string('name', 100);
            $table->char('currency_code', 3);
            $table->string('invoice_type', 10)->default('standard');
            $table->unsignedSmallInteger('payment_terms_days')->default(30); // due_date = generated issue_date + this
            $table->string('recurrence_rule', 255);
            $table->date('anchor_date');
            $table->date('next_run_date')->nullable();
            $table->date('last_run_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->index(['company_id', 'is_active', 'next_run_date']);
        });

        Schema::create('ACCOUNTING.recurring_ar_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_ar_template_id')->constrained('ACCOUNTING.recurring_ar_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 255);
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('ACCOUNTING.tax_codes');
            $table->foreignId('revenue_account_id')->constrained('ACCOUNTING.accounts');
        });

        Schema::create('ACCOUNTING.recurring_generation_log', function (Blueprint $table) {
            $table->id();
            $table->string('template_type', 20); // journal|ar_invoice
            $table->unsignedBigInteger('template_id');
            $table->date('run_date'); // the occurrence date generated, not the wall-clock date the command ran
            $table->string('generated_subject_type', 60);
            $table->unsignedBigInteger('generated_subject_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['template_type', 'template_id', 'run_date']);
            $table->index(['generated_subject_type', 'generated_subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.recurring_generation_log');
        Schema::dropIfExists('ACCOUNTING.recurring_ar_template_lines');
        Schema::dropIfExists('ACCOUNTING.recurring_ar_templates');
        Schema::dropIfExists('ACCOUNTING.recurring_journal_template_lines');
        Schema::dropIfExists('ACCOUNTING.recurring_journal_templates');
    }
};
