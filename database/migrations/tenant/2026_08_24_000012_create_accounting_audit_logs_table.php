<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3O Audit & Compliance — mirrors DMS.access_logs' append-only shape exactly (see
 * AuditLog model). subject_type follows this module's own existing polymorphic-ref
 * convention (e.g. 'accounting.ar_invoices', see ArInvoiceService::create()), not a
 * bigint FK, since one table now spans 10+ different subject tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('action', 30); // journal_created|journal_posted|journal_reversed|period_closed|period_reopened|invoice_posted|bill_posted|payment_posted|tax_document_issued|tax_document_cancelled|master_data_changed
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->jsonb('before_snapshot')->nullable();
            $table->jsonb('after_snapshot')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.audit_logs');
    }
};
