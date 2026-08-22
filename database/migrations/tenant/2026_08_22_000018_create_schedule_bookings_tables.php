<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule module — build order step 3 (SCHEDULE_SPECS.md §5): sched_bookings
 * (§3D/§3E resource booking pivot) and sched_working_hours (§3D/§3E optional
 * per-resource weekly availability window). Needs resources (step 1) and
 * sched_items (step 2), both already migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SCHEDULE.sched_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sched_item_id')->constrained('SCHEDULE.sched_items')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('SCHEDULE.resources');

            $table->unique(['sched_item_id', 'resource_id']);
            $table->index('resource_id');
        });

        Schema::create('SCHEDULE.sched_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('SCHEDULE.resources')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday, matching ISO/JS convention (§3D/§3E)
            $table->time('start_time');
            $table->time('end_time');

            $table->unique(['resource_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SCHEDULE.sched_working_hours');
        Schema::dropIfExists('SCHEDULE.sched_bookings');
    }
};
