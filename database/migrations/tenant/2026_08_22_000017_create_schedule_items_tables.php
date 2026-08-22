<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule module — build order step 2 (SCHEDULE_SPECS.md §5): sched_items,
 * the unified Task/Event backbone (§3B/§3C), plus sched_attendees (§3C —
 * event attendees, and task watchers via the same table). Resource booking
 * (sched_bookings, §3D/§3E), recurrence exceptions (§3F), and conference
 * links (§3G) are later builds — not referenced here.
 *
 * Status/type/priority/role values are app-validated (varchar + constants on
 * the model), not DB CHECK constraints — same convention as WNE's migration
 * (2026_08_20_000006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SCHEDULE.sched_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // app-set at creation, same pattern as WNE.wrkflow_instances
            $table->string('type', 10); // task|event
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('subject_type', 100)->nullable(); // optional polymorphic link, e.g. 'legal.case_hdrs' — NOT a FK (§3B/§3C)
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('recurrence_rule', 255)->nullable(); // RFC 5545 RRULE subset (§3F) — stored now, expanded later
            $table->string('status', 15);
            $table->string('priority', 10)->nullable(); // task only
            $table->timestamp('due_at')->nullable(); // task only
            $table->timestamp('start_at')->nullable(); // event only
            $table->timestamp('end_at')->nullable(); // event only
            $table->boolean('all_day')->default(false); // event only
            $table->string('location', 255)->nullable(); // event only, free text
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Partial indexes: no Blueprint API for a WHERE clause on an index — same
        // pattern as WNE's wrkflow_instance_steps due_at index.
        DB::statement(
            "CREATE INDEX idx_schedule_sched_items_due ON \"SCHEDULE\".sched_items (due_at) WHERE type = 'task' AND status NOT IN ('done', 'cancelled')"
        );
        DB::statement(
            "CREATE INDEX idx_schedule_sched_items_range ON \"SCHEDULE\".sched_items (start_at, end_at) WHERE type = 'event'"
        );

        Schema::create('SCHEDULE.sched_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sched_item_id')->constrained('SCHEDULE.sched_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->string('role', 10)->default('attendee'); // attendee|watcher — item owner already lives on sched_items.owner_id

            $table->unique(['sched_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SCHEDULE.sched_attendees');
        Schema::dropIfExists('SCHEDULE.sched_items');
    }
};
