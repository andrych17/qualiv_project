<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('central_invoices')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            // Only bank_transfer in MVP — reserved values for a Future Version gateway
            // (CENTRAL_SPECS.md §3F) are added as new values later, not a schema change.
            $table->string('method')->default('bank_transfer');
            $table->timestamp('paid_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_payments');
    }
};
