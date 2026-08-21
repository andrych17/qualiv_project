<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3M Retry Mechanism & Dead Letter Queue.
 *
 * Deliberately additive and opt-in: `max_attempts` defaults to NULL, and RetryService
 * treats "no row" or "max_attempts <= 1" as "don't retry" — the exact behavior every
 * channel already had before this migration (SmsDriver/PushDriver's own "not configured"
 * checks return a single failure with nothing a retry could fix; PushDriver's "not yet
 * implemented" fails identically every attempt). A channel opts into multi-attempt retry
 * (and the DLQ) by explicitly setting max_attempts > 1. "Exponential by default" (spec)
 * describes `backoff_schedule` being NULL, not `max_attempts` defaulting above 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('WNE.msg_channel_configs', function (Blueprint $table) {
            $table->unsignedInteger('max_attempts')->nullable()->after('enabled');
            $table->json('backoff_schedule')->nullable()->after('max_attempts'); // e.g. [60,300,1800,7200] seconds; NULL = exponential default
        });

        Schema::table('WNE.msg_notification_deliveries', function (Blueprint $table) {
            $table->unsignedInteger('attempt')->default(1)->after('status');
            $table->json('retry_history')->default('[]')->after('error'); // [{attempt, error, occurred_at}, ...] — §3O's msg_delivery_events doesn't exist yet, so this is the delivery's own self-contained trail
        });

        // Full message + failure history snapshot, independent of the delivery/notification
        // rows it came from — an admin inspecting the DLQ shouldn't need those rows to still
        // exist or be unmodified. No unique constraint on delivery_id: a resent delivery that
        // fails out again dead-letters a second time, and both are an honest historical record.
        Schema::create('WNE.msg_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('WNE.msg_notification_deliveries')->cascadeOnDelete();
            $table->foreignId('notification_id')->constrained('WNE.msg_notifications')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users');
            $table->string('subject', 255);
            $table->text('body');
            $table->json('data')->default('{}');
            $table->json('failure_history')->default('[]');
            $table->timestamp('resent_at')->nullable();
            $table->foreignId('resent_by')->nullable()->constrained('users');
            $table->timestamp('discarded_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['delivery_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('WNE.msg_dead_letters');

        Schema::table('WNE.msg_notification_deliveries', function (Blueprint $table) {
            $table->dropColumn(['attempt', 'retry_history']);
        });

        Schema::table('WNE.msg_channel_configs', function (Blueprint $table) {
            $table->dropColumn(['max_attempts', 'backoff_schedule']);
        });
    }
};
