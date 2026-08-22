<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3G PPAT deed fields on the unified LEGAL.deeds table (LEGAL_SPECS.md).
 * Both columns are nullable/only meaningful for category=ppat rows — §3C notarial deeds
 * never set them, same "one table, category-conditional columns" shape the spec itself
 * describes for LEGAL.deeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('LEGAL.deeds', function (Blueprint $table) {
            $table->foreignId('land_object_id')->nullable()->after('matter_id')->constrained('LEGAL.land_objects');
            $table->decimal('transaction_value', 18, 2)->nullable()->after('land_object_id');
        });
    }

    public function down(): void
    {
        Schema::table('LEGAL.deeds', function (Blueprint $table) {
            $table->dropColumn('transaction_value');
            $table->dropConstrainedForeignId('land_object_id');
        });
    }
};
