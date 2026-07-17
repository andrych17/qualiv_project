<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core CUSTOMFIELDS — EAV defs + values (CLAUDE.md §7).
 * ponytail: single value text column; typed cast in service. Ceiling: typed columns if query-heavy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('CUSTOMFIELDS.field_defs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity_type', 50);
            $table->string('code', 50);
            $table->string('label');
            $table->string('field_type', 20); // text|number|date|select
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('seq')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['entity_type', 'code']);
        });

        Schema::create('CUSTOMFIELDS.field_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_def_id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['field_def_id', 'entity_type', 'entity_id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('CUSTOMFIELDS.field_values');
        Schema::dropIfExists('CUSTOMFIELDS.field_defs');
    }
};
