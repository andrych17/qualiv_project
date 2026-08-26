<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PURCHASE module — §3G Vendor Profile, thin 1:1 extension of CRM.partners
 * (role = Vendor). No vendor master duplicate — name/address/contact stay in CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PURCHASE.vendor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->unique()->constrained('CRM.partners');
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('incoterms', 20)->nullable();
            $table->char('preferred_currency', 3)->nullable();
            $table->string('tax_registration_no', 40)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->text('bank_account_encrypted')->nullable(); // encrypted at rest — security-sensitive
            $table->boolean('is_preferred')->default(false);
            $table->string('onboarding_status', 15)->default('pending'); // pending|active|suspended
            $table->timestamps();
        });

        Schema::create('PURCHASE.pur_vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_profile_id')->constrained('PURCHASE.vendor_profiles')->cascadeOnDelete();
            $table->string('doc_type', 15); // license|insurance|tax_cert|other
            $table->string('title', 150);
            $table->unsignedBigInteger('dms_document_id')->nullable(); // informational ref to DMS.documents.id
            $table->date('expiry_date')->nullable();
            $table->timestamps();

            $table->index(['vendor_profile_id', 'expiry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PURCHASE.pur_vendor_documents');
        Schema::dropIfExists('PURCHASE.vendor_profiles');
    }
};
