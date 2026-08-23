<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACCOUNTING module — §3M Indonesian Tax Engine: tax codes (PPN), withholding types
 * (PPh family), the tax-period register (masa pajak), Faktur Pajak + Bukti Potong
 * issuance, and a log of generated Coretax export batches.
 *
 * Built ahead of §3D/§3E (AR/AP) per ACCOUNTING_SPECS.md §5's suggested build order —
 * AR/AP is written against this engine from the start, not retrofitted later. That
 * ordering means `ar_invoices`/`ap_bills` don't exist yet, so `tax_faktur_pajak.
 * ar_invoice_id` / `ap_bill_id` and `tax_bukti_potong.ap_bill_id` are plain nullable
 * columns with NO foreign key today (deviating from ACCOUNTING_SPECS.sql, which is a
 * planning reference, not the shipped migration — same license WNE's migration already
 * took on status/type columns). §3D/§3E adds the FK constraints once those tables exist.
 *
 * Status/type values are app-validated (varchar + constants on the model), not DB CHECK
 * constraints — same convention as every other Accounting/WNE/CRM migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ACCOUNTING.tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('code', 20); // e.g. PPN11
            $table->decimal('rate', 5, 2);
            $table->string('tax_type', 10); // output|input
            $table->foreignId('gl_account_id')->constrained('ACCOUNTING.accounts');
            $table->boolean('is_active')->default(true);

            $table->unique(['company_id', 'code']);
        });

        Schema::create('ACCOUNTING.withholding_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('code', 20); // PPh23, PPh4A2, PPh21, PPh22, PPh15
            $table->string('name', 100);
            $table->decimal('rate', 5, 2);
            $table->boolean('is_final')->default(false);
            $table->foreignId('gl_payable_account_id')->constrained('ACCOUNTING.accounts');
            $table->boolean('is_active')->default(true);

            $table->unique(['company_id', 'code']);
        });

        // Persisted values are only 'open'|'filed' — 'late' is a display-only derived
        // state (TaxPeriod::isLate(), based on due_date), never written to this column.
        // Same "reserved but computed, not a stored transition" treatment as gl_journals'
        // pending_approval.
        Schema::create('ACCOUNTING.tax_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('obligation_type', 5); // ppn|pph
            $table->char('masa_pajak', 7); // 'YYYY-MM'
            $table->date('due_date');
            $table->string('filing_status', 10)->default('open');
            $table->timestamp('filed_at')->nullable();

            $table->unique(['company_id', 'obligation_type', 'masa_pajak']);
        });

        // §3M: "Nomor Seri Faktur Pajak block, tenant-entered from their DJP allocation."
        // No existing platform numbering mechanism fits (SYSCONFIG.config_snums is a single
        // global counter per code, not a per-company allocated range — see SYSCONFIG_SPECS.md
        // §3D's own note that it isn't a replacement for this kind of ledger numbering).
        // Mirrors LEGAL.protocol_books' role: a lockable parent row a service draws from.
        Schema::create('ACCOUNTING.faktur_pajak_number_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('prefix', 30); // e.g. "010.000-26."
            $table->unsignedBigInteger('range_start');
            $table->unsignedBigInteger('range_end');
            $table->unsignedBigInteger('last_issued')->nullable(); // null = nothing issued from this block yet
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ACCOUNTING.tax_faktur_pajak', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('direction', 10); // output (Keluaran) | input (Masukan)
            $table->string('nomor_seri_faktur', 30);
            // No FK yet — see class docblock. 'output' rows set ar_invoice_id, 'input' rows
            // set ap_bill_id; enforced in FakturPajakService, not a DB CHECK (see class docblock).
            $table->unsignedBigInteger('ar_invoice_id')->nullable();
            $table->unsignedBigInteger('ap_bill_id')->nullable();
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->string('buyer_npwp_nik', 30)->nullable();
            $table->decimal('tax_base', 18, 2);
            $table->decimal('ppn_amount', 18, 2);
            $table->string('status', 15)->default('issued'); // issued|replaced|cancelled
            $table->foreignId('replaces_faktur_id')->nullable()->constrained('ACCOUNTING.tax_faktur_pajak');
            $table->timestamp('issued_at')->useCurrent();

            $table->unique(['company_id', 'nomor_seri_faktur']);
            $table->index(['company_id', 'direction', 'status']);
        });

        Schema::create('ACCOUNTING.tax_bukti_potong', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('bp_type', 10); // BP21|BP26|BP23|BP4A2|BPU
            $table->unsignedBigInteger('ap_bill_id')->nullable(); // no FK yet — see class docblock
            $table->foreignId('withholding_type_id')->constrained('ACCOUNTING.withholding_types');
            $table->foreignId('partner_id')->constrained('CRM.partners'); // the party withheld from
            // Gap-free per (company_id, bp_type), same MAX()+1-under-lock mechanism as
            // LEGAL.protocol_entries.sequence_number (§ FakturPajakService/BuktiPotongService
            // docblocks) — bp_number is the formatted display string derived from it.
            $table->unsignedInteger('sequence_no');
            $table->string('bp_number', 30);
            $table->decimal('gross_amount', 18, 2);
            $table->decimal('withheld_amount', 18, 2);
            $table->string('status', 15)->default('issued'); // issued|replaced|cancelled
            $table->foreignId('replaces_bp_id')->nullable()->constrained('ACCOUNTING.tax_bukti_potong');
            $table->timestamp('issued_at')->useCurrent();

            $table->unique(['company_id', 'bp_number']);
            $table->unique(['company_id', 'bp_type', 'sequence_no']);
        });

        Schema::create('ACCOUNTING.tax_coretax_export_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('ACCOUNTING.companies');
            $table->string('batch_type', 20); // faktur_keluaran|faktur_masukan|bukti_potong
            $table->foreignId('tax_period_id')->constrained('ACCOUNTING.tax_periods');
            $table->string('object_key', 500); // R2 XML batch file, tenant/ACCOUNTING/{company}/tax_documents/{yyyy}/{mm}/...
            $table->unsignedInteger('record_count')->default(0);
            $table->foreignId('generated_by')->nullable()->constrained('users');
            $table->timestamp('generated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ACCOUNTING.tax_coretax_export_batches');
        Schema::dropIfExists('ACCOUNTING.tax_bukti_potong');
        Schema::dropIfExists('ACCOUNTING.tax_faktur_pajak');
        Schema::dropIfExists('ACCOUNTING.faktur_pajak_number_blocks');
        Schema::dropIfExists('ACCOUNTING.tax_periods');
        Schema::dropIfExists('ACCOUNTING.withholding_types');
        Schema::dropIfExists('ACCOUNTING.tax_codes');
    }
};
