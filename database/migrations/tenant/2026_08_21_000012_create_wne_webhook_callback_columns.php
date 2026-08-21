<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3G API & Webhook Extensibility Engine.
 *
 * Outbound (`webhook_call`): reuses §3I/§3M's entire messaging pipeline — `webhook` becomes
 * a fifth `MsgChannelConfig::DRIVER_MAP` channel (matching WNE_SPECS.md §4's own
 * `channel_types` list), so retry/backoff/DLQ is the exact same RetryService code path, not
 * a second implementation. The one webhook-specific need — auth headers — gets its own
 * encrypted column on `wrkflow_steps` rather than living in the plain `config` JSON, which
 * holds only the non-sensitive url/method/payload_template.
 *
 * Inbound (`wait_for_callback`): a single-use token lives directly on
 * `wrkflow_instance_steps` (1:1 with the step, no history of multiple tokens needed) rather
 * than a separate table. "Expiry" is deliberately NOT a second timeout concept — a token is
 * only honored while its step is still `waiting_external`; the existing SLA `due_at`
 * mechanism (§3F) is what moves it out of that status on timeout, via a new
 * `fail_step` escalation action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('WNE.wrkflow_steps', function (Blueprint $table) {
            $table->text('webhook_auth_headers')->nullable()->after('config'); // encrypted:array cast — e.g. {"Authorization": "Bearer ..."}
        });

        Schema::table('WNE.wrkflow_instance_steps', function (Blueprint $table) {
            $table->string('callback_token', 128)->nullable()->unique()->after('idempotency_key');
            $table->timestamp('callback_used_at')->nullable()->after('callback_token');
        });

        // No new column on msg_channel_configs: channel/enabled/credentials/max_attempts/
        // backoff_schedule already generalize to any channel string, 'webhook' included.

        // Partial index from the §3C migration only covered pending/in_progress — widen it
        // to waiting_external now that a wait_for_callback step's due_at (its callback
        // timeout) needs to be scannable by the same SLA sweep too.
        // NB: the connection's search_path is 'public' (config/database.php), so the DROP
        // must be schema-qualified — an unqualified name silently no-ops (IF EXISTS swallows
        // the miss) and the CREATE below then collides with §3C's original same-named index.
        DB::statement('DROP INDEX IF EXISTS "WNE".idx_wne_wrkflow_instance_steps_due');
        DB::statement(
            "CREATE INDEX idx_wne_wrkflow_instance_steps_due ON \"WNE\".wrkflow_instance_steps (due_at) WHERE status IN ('pending', 'in_progress', 'waiting_external')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS "WNE".idx_wne_wrkflow_instance_steps_due');
        DB::statement(
            "CREATE INDEX idx_wne_wrkflow_instance_steps_due ON \"WNE\".wrkflow_instance_steps (due_at) WHERE status IN ('pending', 'in_progress')"
        );

        Schema::table('WNE.wrkflow_instance_steps', function (Blueprint $table) {
            $table->dropColumn(['callback_token', 'callback_used_at']);
        });

        Schema::table('WNE.wrkflow_steps', function (Blueprint $table) {
            $table->dropColumn('webhook_auth_headers');
        });
    }
};
