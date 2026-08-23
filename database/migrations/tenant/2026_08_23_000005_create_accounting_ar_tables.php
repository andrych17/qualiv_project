<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3D Accounts Receivable: customer invoices, payment
 * application, credit notes. Accounting is the platform's only AR ledger
 * (§3D rule) — no other module keeps parallel invoice/payment tables.
 *
 * Deviations from ACCOUNTING_SPECS.sql (a planning reference, not the shipped
 * migration — same license taken in every prior Accounting migration):
 * - `ar_payments.cash_gl_account_id` replaces the reference schema's
 *   `bank_account_id`: §3F Cash & Banks (which owns `bank_accounts`) is built
 *   AFTER AR per §5's build order, so there is no master row to point at yet.
 *   A GL cash/bank account is what posting actually needs regardless; §3F adds
 *   a nullable `bank_account_id` column on top of this one later (additive,
 *   not a replacement — the GL account stays the posting target either way).
 * - `ar_invoices.credited_amount` is new (not in the reference schema): open
 *   balance is `total_amount - paid_amount - credited_amount`, one formula
 *   used everywhere (status, aging). Folding credit notes into `paid_amount`
 *   would conflate "cash received" with "amount forgiven" and break the
 *   AR-control-account-equals-sum-of-open-balances guarantee (§3D rule).
 * - `companies.ar_control_account_id` is new: §3D needs one designated AR
 *   control account per company to post against (customization-ladder rung 1
 *   territory — tenant-configurable, not hardcoded to an account code).
 *   AccountService::seedStarterCoa() sets it automatically from the starter
 *   COA's '11000 Piutang Usaha' row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->foreignId('ar_control_account_id')->nullable()->after('coa_template_code')->constrained('ACCOUNTING.accounts');
        });

        Schema::create('ACCOUNTING.ar_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->string('invoice_no', 40);
            $table->string('invoice_type', 10)->default('standard'); // standard|deposit — never 'credit_memo' (§3D rule, see ar_credit_notes)
            $table->char('currency_code', 3);
            $table->date('issue_date');
            $table->date('due_date');
            // Originating record one hop away (e.g. a Sales Order carries its own Legal
            // pointer) — Accounting only needs this generic pointer (§3D). Sales doesn't
            // exist yet, so this stays null for every invoice created from this module's
            // own screens today; populated once InvoiceRequested has a real caller.
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 15)->default('draft'); // draft|posted|partially_paid|paid|void
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('credited_amount', 18, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->unique(['company_id', 'invoice_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ACCOUNTING.ar_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ar_invoice_id')->constrained('ACCOUNTING.ar_invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 255);
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('ACCOUNTING.tax_codes');
            $table->foreignId('revenue_account_id')->constrained('ACCOUNTING.accounts');
            $table->decimal('line_amount', 18, 2); // qty*unit_price - discount_amount, computed at save (§3D)
            $table->decimal('tax_amount', 18, 2)->default(0); // line_amount * tax_code.rate, computed at save
        });

        Schema::create('ACCOUNTING.ar_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->foreignId('cash_gl_account_id')->constrained('ACCOUNTING.accounts'); // see class docblock
            $table->char('currency_code', 3);
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->string('memo', 255)->nullable();
            $table->string('status', 15)->default('draft'); // draft|posted|void
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->index(['company_id', 'partner_id']);
        });

        Schema::create('ACCOUNTING.ar_payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ar_payment_id')->constrained('ACCOUNTING.ar_payments')->cascadeOnDelete();
            $table->foreignId('ar_invoice_id')->constrained('ACCOUNTING.ar_invoices');
            $table->decimal('applied_amount', 18, 2);

            $table->unique(['ar_payment_id', 'ar_invoice_id']);
        });

        Schema::create('ACCOUNTING.ar_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('partner_id')->constrained('CRM.partners');
            // Nullable — a credit note can reduce a specific invoice or stand alone
            // against a partner's balance (§3D). v1 UI only exposes the invoice-linked
            // path (from the invoice's own screen); the standalone path is schema-ready
            // for a future caller.
            $table->foreignId('ar_invoice_id')->nullable()->constrained('ACCOUNTING.ar_invoices');
            $table->string('credit_note_no', 40);
            $table->date('credit_date');
            $table->decimal('amount', 18, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('revenue_account_id')->constrained('ACCOUNTING.accounts');
            $table->string('status', 15)->default('draft'); // draft|posted|void
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'credit_note_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.ar_credit_notes');
        Schema::dropIfExists('ACCOUNTING.ar_payment_applications');
        Schema::dropIfExists('ACCOUNTING.ar_payments');
        Schema::dropIfExists('ACCOUNTING.ar_invoice_lines');
        Schema::dropIfExists('ACCOUNTING.ar_invoices');
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ar_control_account_id');
        });
    }
};
