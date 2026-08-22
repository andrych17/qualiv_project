<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3F Notary Protocol (LEGAL_SPECS.md). protocol_entries is append-only at
 * the app layer (enforced on the model, DMS.access_logs precedent) — sequence_number is
 * assigned atomically inside the same transaction that signs a deed (§5 protocol ledger
 * integrity), via a row lock on the active protocol_books row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.protocol_books', function (Blueprint $table) {
            $table->id();
            $table->string('book_type', 30); // repertorium|legalisasi|waarmerking|protes|daftar_wasiat|lain_lain
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('volume')->default(1);
            $table->string('status', 20)->default('active'); // active|closed|handed_over
            $table->foreignId('notary_user_id')->constrained('users');
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->string('handed_over_to')->nullable();
            $table->date('handed_over_at')->nullable();
            $table->timestamps();
            $table->unique(['book_type', 'year', 'volume']);
        });

        Schema::create('LEGAL.protocol_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('LEGAL.protocol_books');
            $table->foreignId('deed_id')->nullable()->constrained('LEGAL.deeds');
            $table->unsignedInteger('sequence_number');
            $table->date('entry_date');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['book_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.protocol_entries');
        Schema::dropIfExists('LEGAL.protocol_books');
    }
};
