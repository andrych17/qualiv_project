<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Snapshotted, not a live FK to central_plans — a later price change never
            // rewrites a past invoice (CENTRAL_SPECS.md §3D/§3E).
            $table->string('plan_code');
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->string('status')->default('issued');
            $table->decimal('amount_total', 14, 2);
            $table->string('currency', 3)->default('IDR');
            $table->date('due_date');
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_invoices');
    }
};
