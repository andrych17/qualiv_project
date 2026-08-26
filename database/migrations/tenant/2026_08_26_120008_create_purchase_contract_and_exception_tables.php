<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE module — §3H Contract Management (basic register) and §3K Exception
 * Management Engine (append-style log feeding the dashboard/exception strip).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.pur_contract_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_id')->constrained('CRM.partners');
            $table->string('title', 150);
            $table->string('type', 15)->default('project'); // framework|blanket|project
            $table->decimal('value', 18, 2)->nullable();
            $table->char('currency_code', 3)->default('IDR');
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('notice_period_days')->default(30);
            $table->unsignedBigInteger('dms_document_id')->nullable();
            $table->string('status', 15)->default('draft'); // draft|active|expiring_soon|expired|renewed|terminated
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['status', 'end_date']);
        });

        Schema::create('PURCHASE.pur_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('exception_type', 20);
            // overdue_approval|late_delivery|price_variance|budget_flag|unmatched_invoice
            $table->string('subject_type', 100); // e.g. purchase.pur_order_hdrs
            $table->unsignedBigInteger('subject_id');
            $table->string('summary', 255);
            $table->string('status', 15)->default('open'); // open|resolved|dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'exception_type']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_exceptions');
        Schema::dropIfExists('PURCHASE.pur_contract_hdrs');
    }
};
