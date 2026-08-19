<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_dunning_log', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('central_invoices')->cascadeOnDelete();
            // Negative = before due, positive = past due (CENTRAL_SPECS.md §3G).
            $table->integer('offset_days');
            $table->string('channel')->default('email');
            $table->timestamp('sent_at');
            $table->timestamps();

            // The literal idempotency guard (§5): a given (tenant, invoice, offset) can only
            // ever fire once, no matter how many times the reminder job runs or overlaps.
            $table->unique(['tenant_id', 'invoice_id', 'offset_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_dunning_log');
    }
};
