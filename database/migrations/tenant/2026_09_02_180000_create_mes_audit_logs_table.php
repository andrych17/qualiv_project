<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES_SPECS.md §3U / §4 — Digital Audit Trail. Field-level change log for governance-sensitive
 * edits to already-recorded data — distinct from `mes_prod_events` (§3C), which is the
 * *business* action stream. Same append-only, per-module `*_audit_logs` convention as
 * `SYSCONFIG.config_audit_logs`, `ACCOUNTING.audit_logs`. Per MES_SPECS.sql's DDL for this
 * section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('MES.mes_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 20);
            $table->foreignId('actor_id')->constrained('users');
            $table->jsonb('before_snapshot')->nullable();
            $table->jsonb('after_snapshot')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('MES.mes_audit_logs');
    }
};
