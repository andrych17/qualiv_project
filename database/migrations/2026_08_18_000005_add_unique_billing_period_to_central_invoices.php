<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_invoices', function (Blueprint $table) {
            // Idempotency key for the recurring generation job (CENTRAL_SPECS.md §5) — a
            // period already billed can never get a second invoice, even if the job re-runs.
            $table->unique(['tenant_id', 'plan_code', 'billing_period_start'], 'central_invoices_tenant_plan_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('central_invoices', function (Blueprint $table) {
            $table->dropUnique('central_invoices_tenant_plan_period_unique');
        });
    }
};
