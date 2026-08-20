<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SYSCONFIG_SPECS.md additive alignment — do not rewrite live config_* tables.
 * Adds scoped consts, serial reset/padding, tenant_modules, audit log, menu.module_code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('SYSCONFIG.config_consts', function (Blueprint $table) {
            $table->string('appl_id', 20)->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('value')->nullable();
            $table->string('value_type', 10)->default('text');
            $table->date('effective_date')->nullable();
            $table->boolean('is_active')->default(true);
        });

        DB::statement('ALTER TABLE "SYSCONFIG".config_consts ADD CONSTRAINT config_consts_value_type_check CHECK (value_type IN (\'text\', \'number\', \'bool\', \'date\'))');
        DB::statement('CREATE UNIQUE INDEX config_consts_scope_unique ON "SYSCONFIG".config_consts (appl_id, group_id, user_id, const_group, group_code) NULLS NOT DISTINCT WHERE is_active');
        DB::statement('CREATE INDEX idx_sysconfig_config_consts_active ON "SYSCONFIG".config_consts (const_group, group_code, is_active)');

        Schema::table('SYSCONFIG.config_snums', function (Blueprint $table) {
            $table->unsignedInteger('padding_length')->nullable();
            $table->string('reset_rule', 10)->default('never');
            $table->timestampTz('last_reset_at')->nullable();
        });

        DB::statement('ALTER TABLE "SYSCONFIG".config_snums ADD CONSTRAINT config_snums_reset_rule_check CHECK (reset_rule IN (\'never\', \'yearly\', \'monthly\'))');

        Schema::table('SYSCONFIG.config_menus', function (Blueprint $table) {
            $table->string('module_code', 20)->nullable();
        });

        Schema::create('SYSCONFIG.tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->string('module_code', 20)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('activated_at')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE "SYSCONFIG".tenant_modules ADD CONSTRAINT tenant_modules_module_code_check CHECK (module_code IN (\'WNE\', \'DMS\', \'CRM\', \'SCHEDULE\', \'INVENTORY\', \'ACCOUNTING\', \'PURCHASE\', \'SALES\', \'HCM\', \'PAYROLL\', \'PERFORMANCE\', \'AIINSIGHT\', \'LEGAL\'))');

        Schema::create('SYSCONFIG.config_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 30);
            $table->unsignedBigInteger('record_id');
            $table->string('action', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->jsonb('before_value')->nullable();
            $table->jsonb('after_value')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE "SYSCONFIG".config_audit_logs ADD CONSTRAINT config_audit_logs_table_name_check CHECK (table_name IN (\'config_consts\', \'config_snums\', \'tenant_modules\'))');
        DB::statement('ALTER TABLE "SYSCONFIG".config_audit_logs ADD CONSTRAINT config_audit_logs_action_check CHECK (action IN (\'created\', \'updated\', \'deactivated\', \'serial_corrected\'))');
        DB::statement('CREATE INDEX idx_sysconfig_config_audit_logs_record ON "SYSCONFIG".config_audit_logs (table_name, record_id, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('SYSCONFIG.config_audit_logs');
        Schema::dropIfExists('SYSCONFIG.tenant_modules');

        Schema::table('SYSCONFIG.config_menus', function (Blueprint $table) {
            $table->dropColumn('module_code');
        });

        Schema::table('SYSCONFIG.config_snums', function (Blueprint $table) {
            $table->dropColumn(['padding_length', 'reset_rule', 'last_reset_at']);
        });

        Schema::table('SYSCONFIG.config_consts', function (Blueprint $table) {
            $table->dropColumn(['appl_id', 'group_id', 'user_id', 'value', 'value_type', 'effective_date', 'is_active']);
        });
    }
};
