<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->string('entity_type');
            $table->string('entity_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            // Append-only: created_at only, no updated_at (CENTRAL_SPECS.md §3I).
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_audit_logs');
    }
};
