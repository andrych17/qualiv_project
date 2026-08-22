<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — §3M Field Operations (LEGAL_SPECS.md), web-CRUD slice only. `schedule_item_id`
 * has no FK constraint and no writer yet — SCHEDULE.sched_items doesn't exist in this codebase
 * (Schedule is a separate Core module, not yet built; user confirmed "Schedule dibangun nanti").
 * Wire the FK + the mobile `api/v1/legal/field-visits/*` surface (§5's justified exception) once
 * Schedule ships and Sanctum is set up — this is the same deferred-wiring precedent already used
 * for deed_number (§3F) and the tax gate (§3K).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('LEGAL.field_visit_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->json('default_checklist')->nullable(); // list<string> of checklist item labels
            $table->boolean('is_active')->default(true);
        });

        Schema::create('LEGAL.field_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->nullable()->constrained('LEGAL.matters');
            $table->foreignId('land_object_id')->nullable()->constrained('LEGAL.land_objects');
            $table->foreignId('deed_id')->nullable()->constrained('LEGAL.deeds');
            $table->foreignId('visit_type_id')->constrained('LEGAL.field_visit_types');
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->unsignedBigInteger('schedule_item_id')->nullable(); // SCHEDULE.sched_items — no FK yet, see class docblock
            $table->string('status', 20)->default('scheduled'); // scheduled|checked_in|completed
            $table->timestamp('checked_in_at')->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->json('checklist_result')->nullable(); // list<{label, done, note}>
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('LEGAL.field_visits');
        Schema::dropIfExists('LEGAL.field_visit_types');
    }
};
