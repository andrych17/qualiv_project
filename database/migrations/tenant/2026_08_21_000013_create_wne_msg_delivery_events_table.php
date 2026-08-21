<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3O Observability & Tracking Engine.
 *
 * Append-only, one row per lifecycle event per `msg_notification_deliveries` row — no
 * update/delete at the app layer, same convention as `wrkflow_audit_logs` (WNE_SPECS.md §4).
 * `provider_payload` carries whatever raw detail a provider webhook (SendGrid/Twilio, §3O)
 * reported; internal pipeline events (created/queued/sending/sent/retrying/dead_lettered)
 * have none, so it's nullable rather than defaulting to '{}'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('WNE.msg_delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('WNE.msg_notification_deliveries')->cascadeOnDelete();
            $table->string('event_type', 20); // created|queued|sending|sent|delivered|opened|bounced|failed|retrying|dead_lettered
            // Microsecond precision (Laravel's timestamp() defaults to whole-second on
            // Postgres) — several internal pipeline events can legitimately land in the same
            // second; `id` is still the authoritative tiebreak for the timeline (see the
            // model's events() relation) since a provider's own reported time isn't
            // guaranteed to match processing order either.
            $table->timestamp('occurred_at', 6);
            $table->json('provider_payload')->nullable();

            $table->index(['delivery_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('WNE.msg_delivery_events');
    }
};
