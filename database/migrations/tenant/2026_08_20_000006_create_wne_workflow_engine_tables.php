<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3C Workflow Instance Engine (state management & persistence),
 * plus the structural tables it depends on to resolve a published definition
 * (§3B/§3E: categories -> definitions -> versions -> steps -> transitions).
 * Schema WNE already exists per tenant provisioning (App\Jobs\CreateModuleSchemas).
 *
 * Scope: only what §3C needs to start/advance/complete/fail/cancel an instance.
 * SLA/escalation (§3F), webhooks/callbacks (§3G), the Task Inbox view (§3H), and
 * every Notification table (§3I-§3O) are later builds, per WNE_SPECS.md's own
 * suggested build order — their columns are NOT reserved here to avoid guessing
 * at a shape those sections haven't been built against yet.
 *
 * Status/type values are app-validated (varchar + constants on the model), not
 * DB CHECK constraints — matches the convention already shipped in CRM's
 * migrations rather than WNE_SPECS.sql's CHECK-constrained reference schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('WNE.wrkflow_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('WNE.wrkflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique(); // what a calling module references, e.g. 'hcm.leave_approval' (§3B)
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('WNE.wrkflow_categories');
            $table->string('status', 15)->default('draft'); // draft|published|unpublished
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Immutable per-publish snapshot (§3B/§3E): editing a published definition
        // always edits a new draft; publishing again creates version N+1. No status
        // column here — "currently published" is resolved via the parent definition's
        // status + this table's highest version_no (§3E enforcement, WNE_SPECS.sql).
        Schema::create('WNE.wrkflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id')->constrained('WNE.wrkflow_definitions')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->timestamp('published_at')->useCurrent();
            $table->foreignId('published_by')->nullable()->constrained('users');

            $table->unique(['definition_id', 'version_no']);
        });

        Schema::create('WNE.wrkflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('WNE.wrkflow_versions')->cascadeOnDelete();
            $table->string('step_code', 50); // stable within a version; used by transitions and entry-step lookup
            $table->string('type', 20); // approval|task|condition|parallel_split|parallel_join|webhook_call|wait_for_callback|notify
            $table->json('config')->default('{}'); // assignee rule / condition expr / webhook url+template / template ref, per type (§3B)
            $table->decimal('pos_x', 10, 2)->nullable(); // unused by v1 form UI; captured so a future canvas needs no migration (§3B)
            $table->decimal('pos_y', 10, 2)->nullable();
            $table->boolean('is_entry_step')->default(false);

            $table->unique(['version_id', 'step_code']);
        });

        // Exactly one entry step per version — where WorkflowService::start() creates
        // the first wrkflow_instance_steps row (§3C). Partial index: no Blueprint API
        // for a WHERE clause on a unique index (same pattern as CRM's migration).
        DB::statement('CREATE UNIQUE INDEX uq_wne_wrkflow_steps_entry ON "WNE".wrkflow_steps (version_id) WHERE is_entry_step');

        Schema::create('WNE.wrkflow_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_step_id')->constrained('WNE.wrkflow_steps')->cascadeOnDelete();
            // No cascade on to_step_id: a step being deleted as someone else's "from" step
            // must not silently vanish as this transition's target too.
            $table->foreignId('to_step_id')->constrained('WNE.wrkflow_steps');
            $table->json('condition_expression')->nullable(); // NULL = the mandatory default/"else" transition (§3D)
            $table->integer('seq')->default(0); // evaluation order — first matching transition wins (§3D)

            $table->index(['from_step_id', 'seq']);
        });

        Schema::create('WNE.wrkflow_instances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // app-set at creation, same pattern as CRM.partners
            // No cascade: deleting a definition/version must never erase instance history.
            $table->foreignId('definition_version_id')->constrained('WNE.wrkflow_versions'); // fixed at start, never updated (§3E/§5)
            $table->string('subject_type', 100)->nullable(); // optional polymorphic link, e.g. 'hcm.leave_requests' — NOT a FK (§3C)
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 15)->default('running'); // running|completed|failed|cancelled
            $table->json('payload')->default('{}'); // snapshot taken at start (§3D) — never a live re-query
            $table->foreignId('started_by')->nullable()->constrained('users');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('status');
        });

        Schema::create('WNE.wrkflow_instance_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained('WNE.wrkflow_instances')->cascadeOnDelete();
            // No cascade: a step definition being deleted must not erase instance history either.
            $table->foreignId('step_id')->constrained('WNE.wrkflow_steps');
            $table->string('status', 20)->default('pending'); // pending|in_progress|completed|failed|skipped|cancelled|waiting_external
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->string('assigned_role', 50)->nullable();
            $table->unsignedBigInteger('assigned_team_id')->nullable(); // informational only, no FK (§5 — WNE assumes no "team" owner)
            $table->timestamp('due_at')->nullable(); // computed at step-start once SLA rules (§3F) exist; NULL until then
            $table->unsignedInteger('attempt')->default(1);
            $table->string('idempotency_key', 150)->nullable()->unique(); // derived (instance_id, step_id, attempt) — §5 core design decision
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('decision', 50)->nullable(); // approve|reject|a custom decision set defined on the step (§3H)
            $table->text('comment')->nullable();

            $table->index(['assigned_to', 'status']);
            $table->index('instance_id');
        });

        // Partial index: only rows that can still breach an SLA need to be scanned.
        DB::statement(
            "CREATE INDEX idx_wne_wrkflow_instance_steps_due ON \"WNE\".wrkflow_instance_steps (due_at) WHERE status IN ('pending', 'in_progress')"
        );

        // Append-only — no update/delete permitted at the app layer (comment-only
        // immutability, same convention as dms.access_logs / accounting.audit_logs).
        Schema::create('WNE.wrkflow_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->nullable()->constrained('WNE.wrkflow_instances');
            $table->foreignId('instance_step_id')->nullable()->constrained('WNE.wrkflow_instance_steps');
            $table->string('event_type', 30); // instance_started|step_completed|step_failed|instance_completed|instance_failed|instance_cancelled|...
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['instance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('WNE.wrkflow_audit_logs');
        Schema::dropIfExists('WNE.wrkflow_instance_steps');
        Schema::dropIfExists('WNE.wrkflow_instances');
        Schema::dropIfExists('WNE.wrkflow_transitions');
        Schema::dropIfExists('WNE.wrkflow_steps');
        Schema::dropIfExists('WNE.wrkflow_versions');
        Schema::dropIfExists('WNE.wrkflow_definitions');
        Schema::dropIfExists('WNE.wrkflow_categories');
    }
};
