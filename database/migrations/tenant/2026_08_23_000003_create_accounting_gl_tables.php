<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3C General Ledger / Journal Entries: the single posting
 * path (JournalService::post()) every subledger will eventually post through.
 * Only 'manual' is reachable yet — other `source` values are reserved for §3D+
 * engines not built in this pass, same reservation style WNE's migration used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.gl_journals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // app-set at creation, same pattern as WNE.wrkflow_instances
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('fiscal_period_id')->constrained('ACCOUNTING.fiscal_periods');
            $table->date('journal_date');
            $table->char('currency_code', 3);
            $table->string('memo', 255)->nullable();
            // manual is the only source reachable in this pass; ar|ap|asset|inventory|
            // payroll|recurring|allocation are reserved for their own future builds (§3C).
            $table->string('source', 20)->default('manual');
            // pending_approval is reserved for future WNE-routed approval (§3C) — not
            // reachable yet, see JournalService docblock.
            $table->string('status', 20)->default('draft'); // draft|pending_approval|posted|reversed
            $table->string('subject_type', 100)->nullable(); // polymorphic pointer back to whatever triggered this
            $table->string('subject_id', 100)->nullable();
            $table->foreignId('reversed_journal_id')->nullable()->constrained('ACCOUNTING.gl_journals'); // set on the reversing entry
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->index(['company_id', 'fiscal_period_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ACCOUNTING.gl_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('ACCOUNTING.gl_journals')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('ACCOUNTING.accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('ACCOUNTING.cost_centers');
            $table->decimal('debit', 18, 2)->default(0); // always base-currency amount
            $table->decimal('credit', 18, 2)->default(0);
            $table->char('fx_currency_code', 3)->nullable(); // set only for a foreign-currency line (§3L, not enforced yet)
            $table->decimal('fx_amount', 18, 2)->nullable();
            $table->decimal('fx_rate', 18, 6)->nullable();
            $table->string('description', 255)->nullable();

            $table->index('journal_id');
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.gl_journal_lines');
        Schema::dropIfExists('ACCOUNTING.gl_journals');
    }
};
