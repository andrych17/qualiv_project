<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal vertical — LEGAL.cases was a placeholder scaffold; §3B (LEGAL_SPECS.md) specs a
 * richer "Matters (Engagements)" entity. Rename + extend rather than leave the mismatch,
 * since no tenant has real production data on this table yet (CLAUDE.md rename decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres RENAME TO only accepts an unqualified target name within the same
        // schema — Schema::rename() emits a schema-qualified target and fails.
        DB::statement('ALTER TABLE "LEGAL".cases RENAME TO matters');

        Schema::table('LEGAL.matters', function (Blueprint $table) {
            $table->string('matter_type', 100)->nullable()->after('title');
            $table->foreignId('partner_id')->nullable()->after('matter_type')->constrained('CRM.partners');
            $table->foreignId('assigned_to')->nullable()->after('partner_id')->constrained('users');
            $table->date('opened_at')->nullable()->after('status');
            $table->date('target_close_at')->nullable()->after('opened_at');
            $table->foreignId('converted_from_lead_id')->nullable()->after('target_close_at')->constrained('CRM.leads');
        });

        // §3B status enum is open/in_progress/on_hold/closed — the old scaffold's "pending"
        // has no equivalent, map it to the closest fit rather than orphan existing rows.
        DB::table('LEGAL.matters')->where('status', 'pending')->update(['status' => 'on_hold']);
        DB::statement('UPDATE "LEGAL".matters SET opened_at = created_at::date WHERE opened_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('LEGAL.matters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_from_lead_id');
            $table->dropColumn('target_close_at');
            $table->dropColumn('opened_at');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn('matter_type');
        });

        DB::statement('ALTER TABLE "LEGAL".matters RENAME TO cases');
    }
};
