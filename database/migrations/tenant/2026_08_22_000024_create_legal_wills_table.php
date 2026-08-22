<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3D Wasiat (Wills). Extends the unified LEGAL.deeds model
 * (category=notary, deed_type=wasiat) with the statutory Daftar Pusat Wasiat tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.wills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deed_id')->unique()->constrained('LEGAL.deeds');
            $table->foreignId('testator_partner_id')->constrained('CRM.partners');
            $table->string('dpw_reg_number', 100)->nullable();
            $table->date('dpw_registered_at')->nullable();
            $table->string('status', 20)->default('drafted'); // drafted|dpw_registered|active|opened|revoked
            $table->text('closing_notes')->nullable(); // logged reason for opened/revoked (§3D — never a silent flip)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.wills');
    }
};
