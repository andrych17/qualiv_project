<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3C Notarial Deeds + §4 storage (LEGAL_SPECS.md). Deed category is
 * denormalized onto LEGAL.deeds (not just derived from deed_type) per §4's own field list,
 * so listing/filtering doesn't need a join to deed_types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.deed_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('category', 10); // notary | ppat
            $table->boolean('requires_tax')->default(false);
            $table->boolean('requires_bpn_registration')->default(false);
            $table->string('default_protocol_book_type', 30)->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('LEGAL.deeds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('matter_id')->nullable()->constrained('LEGAL.matters');
            $table->foreignId('deed_type_id')->constrained('LEGAL.deed_types');
            $table->string('category', 10); // notary | ppat — denormalized from deed_type (§4)
            $table->string('deed_number', 100)->nullable(); // assigned on signing (§3F protocol numbering)
            $table->string('status', 30)->default('draft'); // draft|ready_for_signing|signed|archived
            $table->date('signing_date')->nullable();
            $table->string('minuta_reference', 150)->nullable();
            $table->text('summary')->nullable();
            $table->foreignId('amends_deed_id')->nullable()->constrained('LEGAL.deeds'); // amendment, never edit-in-place once signed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.deeds');
        Schema::dropIfExists('LEGAL.deed_types');
    }
};
