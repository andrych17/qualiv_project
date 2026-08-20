<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CUSTOMFIELDS_SPECS.md additive alignment — module_code filter + field_def_audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('CUSTOMFIELDS.field_defs', function (Blueprint $table) {
            $table->string('module_code', 50)->nullable();
        });

        DB::statement('CREATE INDEX idx_customfields_field_defs_module ON "CUSTOMFIELDS".field_defs (module_code, entity_type)');

        Schema::create('CUSTOMFIELDS.field_def_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_def_id');
            $table->string('action', 15);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->jsonb('before_snapshot')->nullable();
            $table->jsonb('after_snapshot')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE "CUSTOMFIELDS".field_def_audit_logs ADD CONSTRAINT field_def_audit_logs_action_check CHECK (action IN (\'created\', \'updated\', \'deactivated\'))');
        DB::statement('CREATE INDEX idx_customfields_field_def_audit_logs_def ON "CUSTOMFIELDS".field_def_audit_logs (field_def_id, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('CUSTOMFIELDS.field_def_audit_logs');

        Schema::table('CUSTOMFIELDS.field_defs', function (Blueprint $table) {
            $table->dropColumn('module_code');
        });
    }
};
