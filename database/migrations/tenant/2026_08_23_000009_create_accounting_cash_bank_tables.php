<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3F Cash & Bank Management, MVP scope per advisor review:
 * - Cash in/out and transfers post through JournalService with source='manual' so
 *   the existing control-account guard applies for free (see CashTransactionService/
 *   CashTransferService docblocks) — no new bypass of that invariant.
 * - Inter-account transfer is same-currency only in v1 (cross-currency needs a
 *   realized-FX-difference account, the same machinery already deferred for §3L
 *   payments — see CashTransferService docblock).
 * - The "cash book" per account is NOT a separate list backed by cash_transactions
 *   — it's derived live from gl_journal_lines filtered by the account's
 *   gl_account_id, so it includes AR/AP payments and manual journals too, not
 *   just entries made through this screen (see BankAccountController::show()).
 *   cash_transactions/cash_transfers only exist to drive the guided create forms.
 * - Bank statement import is CSV-only; MT940 is deferred (real parser effort with
 *   no consumer yet — matching/reconciliation is §3Q, not built). Staged lines
 *   start and stay 'unmatched' in this pass; no 'ignored' status since nothing
 *   writes it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('name', 150); // e.g. "BCA Operational" — display label, distinct from the bank's own account_number
            $table->string('bank_name', 150)->nullable();
            $table->string('account_number', 100)->nullable(); // stored raw; masked at the controller boundary before it ever reaches a list response (see BankAccountController)
            $table->string('account_holder_name', 150)->nullable();
            $table->char('currency_code', 3);
            $table->foreignId('gl_account_id')->constrained('ACCOUNTING.accounts'); // the cash/bank GL account this book reconciles to — one bank account per GL account
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->unique('gl_account_id');
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('ACCOUNTING.cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('bank_account_id')->constrained('ACCOUNTING.bank_accounts');
            $table->string('direction', 3); // in|out
            $table->date('transaction_date');
            $table->decimal('amount', 18, 2); // bank account's own currency — converted to base at post() time, same as AR/AP
            $table->foreignId('offset_account_id')->constrained('ACCOUNTING.accounts'); // the income/expense/other account on the other side of the entry
            $table->string('description', 255)->nullable();
            $table->string('status', 15)->default('draft'); // draft|posted — create()+post() run together from the guided form, same as ArPaymentController
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['bank_account_id', 'transaction_date']);
        });

        Schema::create('ACCOUNTING.cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('from_bank_account_id')->constrained('ACCOUNTING.bank_accounts');
            $table->foreignId('to_bank_account_id')->constrained('ACCOUNTING.bank_accounts');
            $table->date('transfer_date');
            $table->decimal('amount', 18, 2); // single amount — v1 requires from/to to share a currency (see CashTransferService)
            $table->string('description', 255)->nullable();
            $table->string('status', 15)->default('draft');
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('ACCOUNTING.bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('bank_account_id')->constrained('ACCOUNTING.bank_accounts');
            $table->string('object_key', 255); // raw uploaded file, kept per CLAUDE.md §7B (ACCOUNTING/ owns its own generated/imported artifacts)
            $table->string('original_filename', 255);
            $table->unsignedInteger('line_count');
            $table->foreignId('imported_by')->nullable()->constrained('users');
            $table->timestamp('imported_at');
        });

        Schema::create('ACCOUNTING.bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('ACCOUNTING.bank_statement_imports')->cascadeOnDelete();
            $table->date('line_date');
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 2); // signed: positive = inflow/credit, negative = outflow/debit (standard statement convention)
            $table->string('reference', 100)->nullable();
            $table->string('status', 15)->default('unmatched'); // reserved for §3Q reconciliation — only value reachable in this pass
            $table->timestamp('created_at')->useCurrent();

            $table->index('import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.bank_statement_lines');
        Schema::dropIfExists('ACCOUNTING.bank_statement_imports');
        Schema::dropIfExists('ACCOUNTING.cash_transfers');
        Schema::dropIfExists('ACCOUNTING.cash_transactions');
        Schema::dropIfExists('ACCOUNTING.bank_accounts');
    }
};
