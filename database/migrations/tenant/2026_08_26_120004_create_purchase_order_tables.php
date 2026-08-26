<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PURCHASE module — §3D Purchase Order (PO), with amendment/revision history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.pur_order_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('po_no', 30)->unique();
            $table->foreignId('supplier_id')->constrained('CRM.partners');
            $table->foreignId('pr_id')->nullable()->constrained('PURCHASE.pur_requisition_hdrs');
            $table->foreignId('rfx_id')->nullable(); // set as FK once RFx table exists (added in rfx migration)
            $table->string('ship_to', 255)->nullable();
            $table->string('bill_to', 255)->nullable();
            $table->char('currency_code', 3)->default('IDR');
            $table->string('incoterms', 20)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('status', 20)->default('draft');
            // draft|pending_approval|approved|sent|acknowledged|partially_received|received|closed|cancelled
            $table->unsignedSmallInteger('revision_no')->default(1);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->date('expected_delivery_date')->nullable();
            $table->string('ack_status', 15)->nullable(); // accepted|accepted_with_changes|rejected
            $table->unsignedBigInteger('pdf_document_id')->nullable(); // informational ref to DMS.documents.id
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['status', 'supplier_id']);
        });

        Schema::create('PURCHASE.pur_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('PURCHASE.pur_order_hdrs')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('catalog_item_id')->nullable()->constrained('PURCHASE.pur_catalog_items');
            $table->string('description', 255);
            $table->decimal('qty_ordered', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0); // rollup from pur_receipt_lines
            $table->decimal('unit_price', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->date('expected_delivery_date')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('PURCHASE.categories');
            $table->decimal('local_content_pct', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('PURCHASE.pur_order_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('PURCHASE.pur_order_hdrs')->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_no');
            $table->json('snapshot'); // full header+lines snapshot at that revision — never overwritten
            $table->foreignId('revised_by')->nullable()->constrained('users');
            $table->timestamp('revised_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_order_revisions');
        Schema::dropIfExists('PURCHASE.pur_order_lines');
        Schema::dropIfExists('PURCHASE.pur_order_hdrs');
    }
};
