<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WNE module — §3L Dynamic Template Management.
 *
 * A category can have a template per channel per locale (unique constraint below).
 * `locale` defaults to MsgTemplate::DEFAULT_LOCALE ('en') — no per-user/tenant locale
 * preference exists yet (that's §3J), so v1 always resolves against the one default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('WNE.msg_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('WNE.msg_categories')->cascadeOnDelete();
            $table->string('channel', 20); // email|sms|push|in_app
            $table->string('locale', 10)->default('en');
            $table->string('subject', 255)->nullable(); // email/push title — sms/in_app don't need one
            $table->text('body');
            $table->json('variables')->nullable(); // documented {{var}} names, e.g. ["employee_name","due_date"] — validated against the template text before it can be activated
            $table->boolean('is_active')->default(false);

            $table->unique(['category_id', 'channel', 'locale']);
        });

        // Seeds the one category code this module's own code already dispatches through
        // (SlaEscalationService, §3F) — without this row, no template could ever attach to
        // it, since §3I deliberately doesn't require a msg_categories row to call notify().
        DB::table('WNE.msg_categories')->insert([
            'code' => 'wne.sla_breach',
            'name' => 'Workflow SLA breach',
            'is_mandatory' => false,
            'digestible' => false,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('WNE.msg_templates');
    }
};
