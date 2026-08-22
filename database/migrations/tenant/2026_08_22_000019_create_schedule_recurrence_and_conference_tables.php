<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedule module — build order steps 4 & 5 (SCHEDULE_SPECS.md §5):
 * sched_recurrence_exceptions (§3F — per-occurrence skip/move/modify) and
 * sched_conference_links (§3G — join link per event), plus credentials/config
 * columns on conference_providers so a real provider driver (Zoom) has
 * somewhere tenant-specific to keep its API credentials — same
 * "credentials-gated, per-tenant" pattern as WNE.msg_channel_configs
 * (2026_08_21_000008), not a .env/config/services.php value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('SCHEDULE.conference_providers', function (Blueprint $table) {
            $table->text('credentials')->nullable(); // encrypted JSON — provider secrets (Zoom account/client id+secret, ...)
            $table->json('config')->nullable(); // non-secret settings
        });

        Schema::create('SCHEDULE.sched_recurrence_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sched_item_id')->constrained('SCHEDULE.sched_items')->cascadeOnDelete();
            $table->date('original_occurrence_date');
            $table->string('action', 10); // skipped|moved|modified — app-validated, same convention as other Schedule tables
            $table->timestamp('override_start_at')->nullable(); // set for 'moved'/'modified'
            $table->timestamp('override_end_at')->nullable();

            $table->unique(['sched_item_id', 'original_occurrence_date']);
        });

        Schema::create('SCHEDULE.sched_conference_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sched_item_id')->unique()->constrained('SCHEDULE.sched_items')->cascadeOnDelete();
            $table->foreignId('conference_provider_id')->constrained('SCHEDULE.conference_providers');
            $table->string('join_url', 500);
            $table->string('external_meeting_id', 150)->nullable(); // for future cancel/update calls against the provider
            $table->text('dial_in_info')->nullable(); // free text, not structured — not worth modeling in v1
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SCHEDULE.sched_conference_links');
        Schema::dropIfExists('SCHEDULE.sched_recurrence_exceptions');

        Schema::table('SCHEDULE.conference_providers', function (Blueprint $table) {
            $table->dropColumn(['credentials', 'config']);
        });
    }
};
