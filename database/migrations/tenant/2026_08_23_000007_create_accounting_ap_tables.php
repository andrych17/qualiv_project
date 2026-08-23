<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3E Accounts Payable: vendor bills, payment application, debit
 * notes. Mirrors §3D's AR tables structurally; deviations from that mirror are called
 * out below since they're the whole point of this migration, not accidents.
 *
 * - `ap_bills.bill_no` is the VENDOR's own invoice/reference number (free text, unique
 *   per company+partner — two different vendors may both send "INV-001"), not something
 *   we generate. This is the opposite of `ar_invoices.invoice_no`, which we DO generate
 *   (we're the ones issuing that document). `ap_debit_notes.debit_note_no` is back to
 *   OUR number, same as `ar_credit_notes.credit_note_no` — a debit note is our document.
 * - `ap_bills.vendor_faktur_no` + `withheld_amount` are new (no AR equivalent): a bill
 *   with an input-taxable line needs the vendor's own Faktur Pajak number to call
 *   FakturPajakService::recordInput() (§3M) — fails loud at posting if a taxable line
 *   has no number, same "AR/AP written against the tax engine from the start" discipline
 *   as §3D. `withheld_amount` is the PPh withheld from THIS bill (§3E: "withholding tax
 *   lines reduce the amount actually paid to the vendor but do not reduce the gross
 *   expense recognized") — `total_amount` stays the full gross expense; the payable
 *   liability actually owed to the vendor is `total_amount - withheld_amount`, computed
 *   in `ApBill::openBalance()`, not stored as a separate column.
 * - `ap_payments.status` includes `pending_approval` as a RESERVED, unreachable value —
 *   §3E's own text says payment approval-above-threshold is "the same approval seam as
 *   journals (§3C), reused rather than reinvented." §3C's JournalService doesn't wire that
 *   seam yet (no published WNE definition for it), so building a parallel one here would
 *   be exactly the "parallel approval engine" §5 forbids. Reserved until journals get it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->foreignId('ap_control_account_id')->nullable()->after('ar_control_account_id')->constrained('ACCOUNTING.accounts');
        });

        Schema::create('ACCOUNTING.ap_bills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->string('bill_no', 40); // vendor's own reference — see class docblock
            $table->char('currency_code', 3);
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('vendor_faktur_no', 30)->nullable(); // vendor's Faktur Pajak number, for recordInput() (§3M)
            $table->foreignId('withholding_type_id')->nullable()->constrained('ACCOUNTING.withholding_types');
            $table->string('subject_type', 100)->nullable(); // most commonly purchase.pur_invoice_hdrs, once Purchase exists
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 15)->default('draft'); // draft|posted|partially_paid|paid|void
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('withheld_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0); // gross expense = subtotal + tax_amount
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('debited_amount', 18, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->unique(['company_id', 'partner_id', 'bill_no']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('ACCOUNTING.ap_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ap_bill_id')->constrained('ACCOUNTING.ap_bills')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 255);
            $table->decimal('qty', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->foreignId('tax_code_id')->nullable()->constrained('ACCOUNTING.tax_codes'); // input-type only, enforced in ApBillService
            $table->foreignId('expense_account_id')->constrained('ACCOUNTING.accounts'); // not restricted to type=expense — an AP line can target any non-control account (asset purchase, etc.)
            $table->decimal('line_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
        });

        Schema::create('ACCOUNTING.ap_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->foreignId('cash_gl_account_id')->constrained('ACCOUNTING.accounts');
            $table->char('currency_code', 3);
            $table->date('payment_date');
            $table->decimal('amount', 18, 2);
            $table->string('memo', 255)->nullable();
            $table->string('status', 15)->default('draft'); // draft|pending_approval (reserved)|posted|void
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('ACCOUNTING.currencies');
            $table->index(['company_id', 'partner_id']);
        });

        Schema::create('ACCOUNTING.ap_payment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ap_payment_id')->constrained('ACCOUNTING.ap_payments')->cascadeOnDelete();
            $table->foreignId('ap_bill_id')->constrained('ACCOUNTING.ap_bills');
            $table->decimal('applied_amount', 18, 2);

            $table->unique(['ap_payment_id', 'ap_bill_id']);
        });

        Schema::create('ACCOUNTING.ap_debit_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->foreignId('ap_bill_id')->nullable()->constrained('ACCOUNTING.ap_bills'); // nullable — can stand alone against a partner's balance (v1 UI: invoice-linked only, mirrors §3D)
            $table->string('debit_note_no', 40); // OUR number — see class docblock
            $table->date('debit_date');
            $table->decimal('amount', 18, 2);
            $table->string('reason', 255)->nullable();
            $table->foreignId('expense_account_id')->constrained('ACCOUNTING.accounts');
            $table->string('status', 15)->default('draft'); // draft|posted|void
            $table->foreignId('journal_id')->nullable()->constrained('ACCOUNTING.gl_journals');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'debit_note_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.ap_debit_notes');
        Schema::dropIfExists('ACCOUNTING.ap_payment_applications');
        Schema::dropIfExists('ACCOUNTING.ap_payments');
        Schema::dropIfExists('ACCOUNTING.ap_bill_lines');
        Schema::dropIfExists('ACCOUNTING.ap_bills');
        Schema::table('ACCOUNTING.companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ap_control_account_id');
        });
    }
};
