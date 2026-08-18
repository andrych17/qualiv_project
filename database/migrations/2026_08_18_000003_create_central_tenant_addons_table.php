<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_tenant_addons', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('module_code');
            $table->timestamp('added_at');
            $table->decimal('price_override', 14, 2)->nullable();
            // 'active' / 'removed' — removing an addon is a status flip, never a delete
            // (CENTRAL_SPECS.md §3C).
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_tenant_addons');
    }
};
