<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3J Party/Appearer Management (LEGAL_SPECS.md). identity_snapshot is a
 * JSON copy taken when the party is added, never re-synced from CRM.partners — that's what
 * makes it a snapshot (§5 "why snapshot, not live reference").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.party_role_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('LEGAL.deed_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deed_id')->constrained('LEGAL.deeds')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->foreignId('role_type_id')->constrained('LEGAL.party_role_types');
            $table->json('identity_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.deed_parties');
        Schema::dropIfExists('LEGAL.party_role_types');
    }
};
