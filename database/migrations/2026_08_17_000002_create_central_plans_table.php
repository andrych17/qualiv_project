<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_plans', function (Blueprint $table) {
            $table->id();
            // Matches config/tenant_modules.php's plan keys (starter/legal/full/internal) —
            // this table adds pricing/catalog metadata on top, it doesn't replace that lookup.
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 14, 2)->default(0);
            $table->string('currency', 3)->default('IDR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_plans');
    }
};
