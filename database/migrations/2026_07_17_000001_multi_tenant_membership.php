<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One email may belong to many tenants (netapp1-style switch).
 * Was: email unique → now unique(email, tenant_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user_lookups', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->unique(['email', 'tenant_id']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        Schema::table('tenant_user_lookups', function (Blueprint $table) {
            $table->dropUnique(['email', 'tenant_id']);
            $table->unique(['email']);
        });
    }
};
