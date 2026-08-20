<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3F SLA & Escalation Engine.
 *
 * `wrkflow_sla_rules.step_id`/`version_id` mirror WNE_SPECS.sql's "one or the
 * other" shape (a step-specific rule, or a version-level default for any
 * human step lacking its own), but the SQL spec's CHECK constraint isn't
 * expressed here — same app-validated convention as every other status/type
 * column in this module (see 2026_08_20_000006's header note). Enforced
 * instead by WorkflowDefinitionService exposing two separate methods
 * (setStepSlaRule / setVersionDefaultSlaRule) rather than one method with
 * two nullable params, so "one or the other" is true by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('WNE.wrkflow_sla_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('step_id')->nullable()->constrained('WNE.wrkflow_steps')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('WNE.wrkflow_versions')->cascadeOnDelete();
            $table->decimal('sla_hours', 6, 2);
            $table->string('escalation_action', 30); // reassign_to_role | notify_manager_of_assignee | notify_role
            $table->string('escalation_target', 100)->nullable(); // role code / user reference, per escalation_action
        });

        // Append-only — no update/delete at the app layer (same convention as wrkflow_audit_logs).
        Schema::create('WNE.wrkflow_escalation_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_step_id')->constrained('WNE.wrkflow_instance_steps')->cascadeOnDelete();
            // No cascade: a rule being edited/removed later must not erase past escalation history.
            $table->foreignId('sla_rule_id')->nullable()->constrained('WNE.wrkflow_sla_rules');
            $table->foreignId('escalated_to_user_id')->nullable()->constrained('users');
            $table->string('escalated_to_role', 50)->nullable();
            $table->timestamp('escalated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('WNE.wrkflow_escalation_log');
        Schema::dropIfExists('WNE.wrkflow_sla_rules');
    }
};
