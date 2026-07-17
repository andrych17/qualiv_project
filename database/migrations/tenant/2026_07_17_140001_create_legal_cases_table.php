<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — first cases table in LEGAL schema (CLAUDE.md §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 50)->unique();
            $table->string('title');
            $table->string('status', 30)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.cases');
    }
};
