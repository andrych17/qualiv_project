<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM module — Helpdesk (§3F). Separate engine from After Sales Service (§3E) — shares
 * only CRM.ticket_categories (already created by the §3E migration) — since a ticket can
 * exist with no known Partner yet and is conversation-first (hd_ticket_messages) rather
 * than an internal work log (svc_case_activities).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('CRM.hd_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('CRM.partners');
            // Free-text requester when the caller isn't a known Partner yet (§3F).
            $table->string('requester_name')->nullable();
            $table->string('requester_contact')->nullable();
            $table->string('subject');
            $table->foreignId('category_id')->nullable()->constrained('CRM.ticket_categories');
            $table->string('priority', 10)->default('normal'); // low|normal|high|urgent
            $table->string('status', 20)->default('open'); // open|in_progress|waiting_on_partner|resolved|closed
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->timestamp('sla_due_at')->nullable();
            $table->string('channel', 10)->default('email'); // email|phone|web_form|in_app
            $table->timestamps();

            $table->index(['status', 'sla_due_at']);
        });

        Schema::create('CRM.hd_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('CRM.hd_tickets')->cascadeOnDelete();
            $table->string('direction', 15); // inbound|outbound|internal_note
            $table->text('body');
            $table->foreignId('sender_id')->nullable()->constrained('users');
            $table->string('sender_name')->nullable(); // free-text sender (external, no user account)
            $table->timestamp('sent_at')->useCurrent();

            $table->index(['ticket_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('CRM.hd_ticket_messages');
        Schema::dropIfExists('CRM.hd_tickets');
    }
};
