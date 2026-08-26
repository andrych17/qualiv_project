<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALES module — §3J/§4 Returns Engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SALES.ret_hdrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->foreignId('so_hdr_id')->nullable()->constrained('SALES.so_hdrs');
            $table->unsignedBigInteger('accounting_invoice_id')->nullable(); // ACCOUNTING.ar_invoices.id
            $table->foreignId('customer_id')->constrained('CRM.partners');
            $table->string('reason_code', 50);
            $table->string('status', 15)->default('requested'); // requested|approved|received|refunded|replaced|closed
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('wne_workflow_instance_id')->nullable();
            $table->foreignId('replacement_so_id')->nullable()->constrained('SALES.so_hdrs');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('SALES.ret_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ret_hdr_id')->constrained('SALES.ret_hdrs')->cascadeOnDelete();
            $table->foreignId('so_line_id')->nullable()->constrained('SALES.so_lines');
            $table->decimal('qty_returned', 14, 3);
            $table->string('condition_notes', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SALES.ret_lines');
        Schema::dropIfExists('SALES.ret_hdrs');
    }
};
