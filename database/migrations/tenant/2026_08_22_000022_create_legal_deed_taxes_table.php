<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3K Tax Tracking Engine (LEGAL_SPECS.md). Tracks the client's own
 * PPh Final / BPHTB obligations on a land transfer — deliberately separate from
 * ACCOUNTING's own Indonesian Tax Engine (§3K "Rules/logic"), no journal entry produced here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.deed_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deed_id')->constrained('LEGAL.deeds');
            $table->string('tax_type', 20); // pph_final|bphtb
            $table->foreignId('taxpayer_partner_id')->nullable()->constrained('CRM.partners');
            $table->decimal('base_amount', 18, 2);
            $table->decimal('njop_amount', 18, 2)->nullable();
            $table->decimal('rate', 5, 2);
            $table->decimal('npoptkp_applied', 18, 2)->nullable();
            $table->decimal('computed_amount', 18, 2);
            $table->string('billing_code', 100)->nullable();
            $table->string('ntpn', 100)->nullable();
            $table->string('status', 30)->default('pending'); // pending|billing_code_issued|paid|validated
            $table->timestamps();
            $table->unique(['deed_id', 'tax_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.deed_taxes');
    }
};
