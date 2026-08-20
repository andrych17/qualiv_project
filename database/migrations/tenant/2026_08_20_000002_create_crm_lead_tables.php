<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM module — Leads (§3D). Pre-partner pipeline; a Lead is never itself a
 * Partner (§3D rule) — converted_partner_id is set only once, at conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('CRM.lead_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('CRM.leads', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // free text until conversion
            $table->string('company_name')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('CRM.lead_sources');
            $table->string('stage', 15)->default('new'); // new|contacted|qualified|converted|disqualified
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->decimal('estimated_value', 18, 2)->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('converted_partner_id')->nullable()->constrained('CRM.partners');
            $table->string('disqualify_reason', 100)->nullable(); // app-enforced when stage = disqualified
            $table->timestamps();

            $table->index(['stage', 'owner_id']);
        });

        Schema::create('CRM.lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('CRM.leads')->cascadeOnDelete();
            $table->string('activity_type', 10); // call|email|meeting|note
            $table->text('body')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users');
            $table->timestamp('logged_at')->useCurrent();

            $table->index(['lead_id', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('CRM.lead_activities');
        Schema::dropIfExists('CRM.leads');
        Schema::dropIfExists('CRM.lead_sources');
    }
};
