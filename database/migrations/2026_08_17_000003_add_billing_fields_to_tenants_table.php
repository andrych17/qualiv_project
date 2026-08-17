<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('contact_name')->nullable()->after('plan');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->text('billing_address')->nullable()->after('contact_phone');
            // Access gate for future dunning (CENTRAL_SPECS.md §3G) — nothing sets read_only
            // yet in this MVP pass, column exists so that engine is additive later.
            $table->string('access_status')->default('active')->after('billing_address');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['contact_name', 'contact_email', 'contact_phone', 'billing_address', 'access_status']);
        });
    }
};
