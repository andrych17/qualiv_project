<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3I Multi-Channel Delivery Engine (Notification Module core tables).
 *
 * `channel_types` from WNE_SPECS.sql is deliberately NOT a lookup table here — it's a
 * fixed, platform-defined vocabulary (email/sms/push/in_app), not tenant-editable data,
 * so it gets the same varchar + PHP-constant treatment as every other type/status column
 * in this module (step.type, escalation_action, ...) rather than the lookup-table
 * treatment reserved for genuinely tenant-editable data like msg_categories.
 *
 * `msg_templates` (§3L), `msg_delivery_events` (§3O), and any retry/backoff policy
 * columns (§3M) are NOT created here — v1 sends literal subject/body and tracks status
 * only on the delivery row itself; those sections are later, additive builds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('WNE.msg_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique(); // e.g. 'wne.sla_breach' — matches NotificationRequested's category
            $table->string('name', 200);
            $table->boolean('is_mandatory')->default(false); // opt-out-blocked (§3J, once it exists)
            $table->boolean('digestible')->default(false); // Future Version (§3N) flag, captured now so it's additive later
            $table->json('default_channels')->nullable(); // e.g. ["in_app","email"] — v1's stand-in for §3J's real preference resolution
        });

        // Per-channel provider config for this tenant. `credentials` is encrypted with the
        // app's central APP_KEY (Laravel's `encrypted` cast) — not a per-tenant key, since
        // this platform has none; "encrypted at rest" per WNE_SPECS.md §4 is satisfied at
        // that level, not tenant-isolated key material.
        Schema::create('WNE.msg_channel_configs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20)->unique(); // email|sms|push|in_app
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable(); // encrypted JSON — provider secrets (Twilio SID/token, FCM key, SMTP creds, ...)
            $table->json('config')->nullable(); // non-secret settings (from-email, from-name, ...)
        });

        // Header — one logical notification per resolved recipient. No `msg_templates` FK
        // (§3L not built) — subject/body are literal text authored by the caller.
        Schema::create('WNE.msg_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('category_code', 100); // matches msg_categories.code when a category row exists; not a hard FK — categories aren't required to exist before notify() can be called
            $table->string('recipient_type', 20); // user|role|manager_of_user
            $table->foreignId('recipient_user_id')->nullable()->constrained('users');
            $table->string('recipient_role', 50)->nullable();
            $table->string('subject_type', 100)->nullable(); // polymorphic pointer passthrough from the triggering event, not a FK (§3C convention)
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject', 255);
            $table->text('body');
            $table->json('data')->default('{}'); // template variables — forward-compat for §3L
            $table->string('status', 15)->default('pending'); // pending|sent|failed — 'sent' as soon as ANY channel delivery succeeds (§3I: "an email bounce doesn't hide a successful in-app delivery")
            $table->text('error')->nullable(); // set when the recipient itself couldn't be resolved (empty role, manager_of_user) — no deliveries exist in that case
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_user_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        // One row per (notification, channel) attempt — tracked independently per §3I.
        Schema::create('WNE.msg_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('WNE.msg_notifications')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 15)->default('pending'); // pending|sent|delivered|failed|bounced
            $table->string('provider_message_id', 150)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->index(['notification_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('WNE.msg_notification_deliveries');
        Schema::dropIfExists('WNE.msg_notifications');
        Schema::dropIfExists('WNE.msg_channel_configs');
        Schema::dropIfExists('WNE.msg_categories');
    }
};
