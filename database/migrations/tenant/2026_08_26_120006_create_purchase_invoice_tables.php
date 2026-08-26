<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE module — §3F Invoice Capture & Three-Way Match. This is a procurement
 * intake/matching record, not the AP ledger — ACCOUNTING.ap_bills is the payable,
 * created from here via AccountingService::createBill() once matched (§3F/§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.pur_invoice_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('po_id')->constrained('PURCHASE.pur_order_hdrs');
            $table->foreignId('supplier_id')->constrained('CRM.partners');
            $table->string('supplier_invoice_no', 60);
            $table->date('supplier_invoice_date');
            $table->char('currency_code', 3)->default('IDR');
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('dms_document_id')->nullable(); // attached invoice doc via DMS
            $table->string('submission_channel', 15)->default('manual'); // manual|supplier_upload_link
            $table->string('match_status', 15)->default('pending'); // pending|matched|mismatch
            $table->string('status', 20)->default('captured');
            // captured|pending_approval|approved|sent_to_accounting|posted|paid|rejected
            // informational back-reference once handed to Accounting (§3F)
            $table->unsignedBigInteger('ap_bill_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_invoice_no']);
            $table->index(['po_id', 'status']);
        });

        Schema::create('PURCHASE.pur_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('PURCHASE.pur_invoice_hdrs')->cascadeOnDelete();
            $table->foreignId('po_line_id')->constrained('PURCHASE.pur_order_lines');
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_amount', 18, 2);
        });

        Schema::create('PURCHASE.pur_invoice_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('PURCHASE.pur_invoice_hdrs')->cascadeOnDelete();
            $table->foreignId('po_line_id')->constrained('PURCHASE.pur_order_lines');
            $table->decimal('po_qty', 18, 4);
            $table->decimal('po_price', 18, 2);
            $table->decimal('gr_qty', 18, 4);
            $table->decimal('invoice_qty', 18, 4);
            $table->decimal('invoice_price', 18, 2);
            $table->decimal('qty_variance_pct', 6, 2)->default(0);
            $table->decimal('price_variance_pct', 6, 2)->default(0);
            $table->boolean('within_tolerance')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_invoice_matches');
        Schema::dropIfExists('PURCHASE.pur_invoice_lines');
        Schema::dropIfExists('PURCHASE.pur_invoice_hdrs');
    }
};
