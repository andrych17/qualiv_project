<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3P / §4 — Shift Reference & Handover (Phase 2). No MES-owned shift model — this
 * is the one MES-owned table for the section, since shift *content* (what was running, what
 * needs attention) is production-specific, not an HCM concern. `shift_assignment_id` is a real
 * cross-schema FK into `HCM.shift_assignments` (read-only reference), same pattern
 * `mes_process_phases.recipe_id` already uses for `PP.pp_recipes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_shift_handover_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_assignment_id')->constrained('HCM.shift_assignments');
            // Auto-captured at creation time — orders in progress, open QC holds, open downtime
            // (ShiftHandoverService::snapshot()); a point-in-time record, never recomputed later.
            $table->jsonb('order_summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('shift_assignment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_shift_handover_notes');
    }
};
