<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM module — After Sales Service (§3E). ticket_categories is shared with
 * Helpdesk (§3F, not yet built) per §4's own storage list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('CRM.ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('CRM.svc_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('CRM.partners');
            $table->string('subject');
            $table->foreignId('category_id')->nullable()->constrained('CRM.ticket_categories');
            $table->string('priority', 10)->default('normal'); // low|normal|high|urgent
            $table->string('status', 20)->default('open'); // open|in_progress|waiting_on_partner|resolved|closed
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->timestamp('sla_due_at')->nullable();
            $table->string('subject_type', 100)->nullable(); // informational only — NOT a FK (§5)
            $table->string('subject_id', 100)->nullable();
            $table->timestamp('closed_at')->nullable(); // drives the 7-day reopen grace window (§3E rule)
            $table->timestamps();

            $table->index(['status', 'sla_due_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('CRM.svc_case_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('CRM.svc_cases')->cascadeOnDelete();
            $table->string('activity_type', 15); // note|status_change|attachment
            $table->text('body')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users');
            $table->timestamp('logged_at')->useCurrent();

            $table->index(['case_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('CRM.svc_case_activities');
        Schema::dropIfExists('CRM.svc_cases');
        Schema::dropIfExists('CRM.ticket_categories');
    }
};
