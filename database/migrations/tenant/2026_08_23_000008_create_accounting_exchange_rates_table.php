<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3L Multi Currency, MVP scope. Rate table + the fx_rate a
 * posted AR/AP document actually booked at; realized/unrealized gain-loss and
 * period-end revaluation are explicitly deferred (see ArInvoiceService/
 * ApBillService/ArPaymentService/ApPaymentService docblocks) — nothing here
 * assumes they exist yet.
 *
 * ar_invoices/ap_bills keep storing transaction-currency amounts (what the
 * customer/vendor sees); gl_journal_lines.debit/credit are always base-currency
 * (§3C migration comment) — fx_rate on the invoice/bill is the rate used to
 * convert at post() time, and payments/credit/debit notes settle against that
 * same document without re-resolving a rate (see ArPaymentService docblock for
 * why: settling at a different rate is realized gain/loss, deferred).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->char('currency_code', 3);
            $table->decimal('rate_to_base', 18, 6); // 1 unit of currency_code = this many units of company's base_currency
            $table->date('effective_date');
            $table->string('source', 20)->default('manual'); // manual|feed (no feed driver built yet)
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            // unique() already covers rateFor()'s lookup (company_id, currency_code,
            // effective_date) — a separate index() with the same columns collides on
            // Postgres's 63-char truncated auto-generated name, so there's only one here.
            $table->unique(['company_id', 'currency_code', 'effective_date']);
        });

        // The rate a posted document actually booked at — null until posted, same
        // convention as subtotal/tax_amount/total_amount (computed at post() time).
        Schema::table('ACCOUNTING.ar_invoices', function (Blueprint $table) {
            $table->decimal('fx_rate', 18, 6)->nullable()->after('currency_code');
        });
        Schema::table('ACCOUNTING.ap_bills', function (Blueprint $table) {
            $table->decimal('fx_rate', 18, 6)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('ACCOUNTING.ar_invoices', function (Blueprint $table) {
            $table->dropColumn('fx_rate');
        });
        Schema::table('ACCOUNTING.ap_bills', function (Blueprint $table) {
            $table->dropColumn('fx_rate');
        });
        Schema::dropIfExists('ACCOUNTING.exchange_rates');
    }
};
