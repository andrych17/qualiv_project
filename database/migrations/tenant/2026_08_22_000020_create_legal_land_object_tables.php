<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3H Land Object Registry + §3I Land Due Diligence (LEGAL_SPECS.md).
 * The override columns on due_diligence_checks exist because §3I explicitly allows signing
 * to proceed past a flagged check only with a logged justification — "it must never be silent".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.land_objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('certificate_type', 20); // SHM|HGB|HGU|Hak Pakai|other
            $table->string('certificate_number', 100);
            $table->string('nib', 100)->nullable();
            $table->string('address');
            $table->decimal('area_m2', 12, 2)->nullable();
            $table->string('njop_reference', 150)->nullable();
            $table->foreignId('current_owner_partner_id')->nullable()->constrained('CRM.partners');
            $table->string('status', 20)->default('active'); // active|in_transaction|transferred|disputed
            $table->timestamps();
        });

        Schema::create('LEGAL.due_diligence_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('land_object_id')->constrained('LEGAL.land_objects');
            $table->string('check_type', 30); // sertifikat_validity|pbb_payment_status|blokir_sengketa|zona_nilai_tanah
            $table->string('status', 20)->default('pending'); // pending|clear|flagged
            $table->foreignId('checked_by')->nullable()->constrained('users');
            $table->timestamp('checked_at')->nullable();
            $table->text('result_notes')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('users');
            $table->timestamp('overridden_at')->nullable();
            $table->text('override_justification')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.due_diligence_checks');
        Schema::dropIfExists('LEGAL.land_objects');
    }
};
