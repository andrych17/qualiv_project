<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3J User Preference Center. Additive on top of §3I's msg_categories/
 * msg_notifications tables; a recipient who has never set a preference is unaffected —
 * MessagingService falls back to the category's own default_channels exactly as it did
 * before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Second boolean alongside is_mandatory, not a replacement for it — a category can
        // be one, both, or neither. wne.sla_breach (seeded by the §3L migration) gets it set
        // below: an SLA escalation silenced until a user's quiet hours end would defeat the
        // whole point of escalating.
        Schema::table('WNE.msg_categories', function (Blueprint $table) {
            $table->boolean('is_urgent')->default(false)->after('is_mandatory');
        });

        DB::table('WNE.msg_categories')->where('code', 'wne.sla_breach')->update(['is_urgent' => true]);

        Schema::create('WNE.msg_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('WNE.msg_categories')->cascadeOnDelete();
            $table->json('channels')->nullable(); // NULL = no explicit choice, category default_channels apply
            $table->boolean('opted_out')->default(false); // service-layer guarded: never true when the category is_mandatory
            $table->unique(['user_id', 'category_id']);
        });

        // Quiet hours are per (user, channel) — not per category — so a user can allow
        // in-app anytime while silencing push/SMS overnight.
        Schema::create('WNE.msg_user_quiet_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->time('start_time');
            $table->time('end_time');
            $table->unique(['user_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('WNE.msg_user_quiet_hours');
        Schema::dropIfExists('WNE.msg_user_preferences');
        Schema::table('WNE.msg_categories', function (Blueprint $table) {
            $table->dropColumn('is_urgent');
        });
    }
};
