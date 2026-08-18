<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // This codebase provisions synchronously on tenant creation (CentralTenantService),
            // so 'provisioned' is the only value ever set today — the pending/provisioning
            // gate described in CENTRAL_SPECS.md §3B is not built yet, this column exists so
            // that gate is additive later without a schema change.
            $table->string('provisioning_status')->default('provisioned')->after('access_status');
            $table->string('tenant_db_name')->nullable()->after('provisioning_status');
            $table->timestamp('provisioned_at')->nullable()->after('tenant_db_name');
        });

        // Every tenant that already exists at this point was already fully provisioned (Mode B
        // synchronous provisioning, CLAUDE.md §4) — backfill the deterministic 'tenant_<id>' DB
        // name and treat their own created_at as the provisioning timestamp, so a patched
        // staging/production database doesn't leave every pre-existing tenant with NULLs here.
        DB::statement("UPDATE tenants SET tenant_db_name = 'tenant_' || id, provisioned_at = created_at WHERE tenant_db_name IS NULL");
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['provisioning_status', 'tenant_db_name', 'provisioned_at']);
        });
    }
};
