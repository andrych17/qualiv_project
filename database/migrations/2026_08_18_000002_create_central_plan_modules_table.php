<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_plan_modules', function (Blueprint $table) {
            $table->id();
            $table->string('plan_code');
            $table->foreign('plan_code')->references('code')->on('central_plans')->cascadeOnDelete();
            $table->string('module_code');
            $table->timestamps();

            $table->unique(['plan_code', 'module_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_plan_modules');
    }
};
