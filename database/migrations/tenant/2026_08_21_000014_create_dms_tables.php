<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DMS module — §3A Main Dashboard reads folders/documents/versions/tags/relations/access_logs,
 * so this migration ships nearly the whole DMS_SPECS.sql reference schema up front (every table
 * the 3A drawer's tabs actually query), not because the reference file listed them. §3E's
 * tsvector/search infra, §3F's scheduled retention job, and §3G's OCR columns are still deferred —
 * only `extracted_text` ships now (nullable placeholder per §5 MVP scope note) so that table
 * doesn't need a later breaking migration.
 *
 * Two deliberate divergences from DMS_SPECS.sql, matching WNE's own migration convention:
 * status/type values are app-validated (varchar + model constants), not DB CHECK constraints;
 * and `subject_id` is unsignedBigInteger (every other module's polymorphic ref in this codebase
 * is a bigint FK id, not a free-text VARCHAR).
 *
 * Schema "DMS" is also added to CreateModuleSchemas::SCHEMAS for future tenant provisioning,
 * but that job only runs at provisioning time — already-provisioned tenants never see it, so
 * this migration creates the schema itself too (idempotent either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::connection()->getConfig('username');
        DB::statement('CREATE SCHEMA IF NOT EXISTS "DMS"');
        DB::statement('GRANT ALL ON SCHEMA "DMS" TO "'.$role.'"');

        Schema::create('DMS.doc_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('DMS.retention_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doc_type_id')->constrained('DMS.doc_types');
            $table->unsignedInteger('retention_period_days');
            $table->string('action_on_expiry', 15)->default('notify_only'); // notify_only|archive|delete
            $table->boolean('legal_hold_overridable')->default(true);
            $table->boolean('is_active')->default(true);
        });

        // §3A rule: folder access is enforced at query time, not just UI-hidden. `private` scopes
        // to the folder's creator, `team` currently collapses to tenant-wide — there is no team/
        // membership model yet anywhere in this codebase (WNE's own instance_steps migration calls
        // its `assigned_team_id` "informational only, no FK" for the same reason). Revisit once one
        // exists; until then `team` is honest tenant-wide access, not a narrower guarantee.
        Schema::create('DMS.folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_folder_id')->nullable()->constrained('DMS.folders');
            $table->string('name', 150);
            $table->foreignId('default_doc_type_id')->nullable()->constrained('DMS.doc_types');
            $table->foreignId('default_retention_policy_id')->nullable()->constrained('DMS.retention_policies');
            $table->string('access_flag', 10)->default('tenant'); // private|team|tenant
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('DMS.documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // used in the object storage key path (§4)
            $table->foreignId('folder_id')->nullable()->constrained('DMS.folders');
            $table->foreignId('doc_type_id')->nullable()->constrained('DMS.doc_types');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('subject_type', 100)->nullable(); // owning module ref, e.g. 'legal.case_hdrs' — NOT a FK (§5)
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('status', 10)->default('draft'); // draft|active|archived|expired|purged
            $table->foreignId('current_version_id')->nullable(); // FK added below once document_versions exists
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('retention_policy_id')->nullable()->constrained('DMS.retention_policies');
            $table->boolean('legal_hold')->default(false); // overrides any scheduled retention action (§3F)
            $table->text('extracted_text')->nullable(); // populated later by OCR (Future Version, §5)
            $table->timestamps();

            $table->index(['folder_id', 'status']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('DMS.document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('DMS.documents')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('original_filename', 255);
            $table->char('checksum_sha256', 64);
            $table->string('storage_key', 500); // tenant_{id}/DMS/{module}/{yyyy}/{mm}/{uuid}/v{n}.{ext}, see §4
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('mime_type', 100);
            $table->string('version_note', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('uploaded_at')->useCurrent();

            $table->unique(['document_id', 'version_no']);
        });

        Schema::table('DMS.documents', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('DMS.document_versions');
        });

        Schema::create('DMS.tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
        });

        Schema::create('DMS.document_tags', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained('DMS.documents')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('DMS.tags')->cascadeOnDelete();
            $table->primary(['document_id', 'tag_id']);
        });

        Schema::create('DMS.document_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_document_id')->constrained('DMS.documents');
            $table->foreignId('target_document_id')->constrained('DMS.documents');
            $table->string('relation_type', 15); // amendment_of|supersedes|attachment_of|related_to
            $table->timestamp('created_at')->useCurrent();

            $table->index('source_document_id');
            $table->index('target_document_id');
        });

        // Append-only — no update/delete permitted at the app layer (§3I), same convention as
        // WNE.wrkflow_audit_logs.
        Schema::create('DMS.access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('DMS.documents');
            $table->foreignId('document_version_id')->nullable()->constrained('DMS.document_versions');
            $table->string('action', 20); // upload|view|download|edit_metadata|version_upload|restore|delete|permission_change|hold_applied|hold_released
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('DMS.access_logs');
        Schema::dropIfExists('DMS.document_relations');
        Schema::dropIfExists('DMS.document_tags');
        Schema::dropIfExists('DMS.tags');
        Schema::table('DMS.documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('DMS.document_versions');
        Schema::dropIfExists('DMS.documents');
        Schema::dropIfExists('DMS.folders');
        Schema::dropIfExists('DMS.retention_policies');
        Schema::dropIfExists('DMS.doc_types');
    }
};
