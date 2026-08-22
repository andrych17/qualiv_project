<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule module — build order step 1 (SCHEDULE_SPECS.md §5): master tables
 * only. Everything else (sched_items, bookings, attendees, recurrence,
 * conference links, working hours, calendar feeds) references these and is
 * built in later steps. Schema SCHEDULE already exists per tenant
 * provisioning (App\Jobs\CreateModuleSchemas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SCHEDULE.resource_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100); // extensible list, not a hardcoded enum (§3D)
            $table->boolean('is_active')->default(true);
        });

        Schema::create('SCHEDULE.resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_type_id')->constrained('SCHEDULE.resource_types');
            $table->string('name', 150);
            $table->text('location_notes')->nullable();
            $table->integer('capacity')->nullable(); // informational only in v1 — not enforced/pooled (§3D)
            $table->boolean('is_active')->default(true);

            $table->index(['resource_type_id', 'is_active']);
        });

        Schema::create('SCHEDULE.conference_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique(); // 'manual', 'zoom', 'google_meet', ... (§3G driver map)
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SCHEDULE.conference_providers');
        Schema::dropIfExists('SCHEDULE.resources');
        Schema::dropIfExists('SCHEDULE.resource_types');
    }
};
