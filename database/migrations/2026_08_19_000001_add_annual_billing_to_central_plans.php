<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_plans', function (Blueprint $table) {
            // CENTRAL_SPECS.md §3D — price_annual + the billing interval that decides which
            // price the §3E generation job uses. Monthly stays the default; annual plans
            // fall back to price_monthly when no annual price is configured.
            $table->decimal('price_annual', 15, 2)->nullable()->after('price_monthly');
            $table->string('billing_cycle')->default('monthly')->after('price_annual');
        });
    }

    public function down(): void
    {
        Schema::table('central_plans', function (Blueprint $table) {
            $table->dropColumn(['price_annual', 'billing_cycle']);
        });
    }
};
