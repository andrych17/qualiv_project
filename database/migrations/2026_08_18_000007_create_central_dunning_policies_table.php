<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_dunning_policies', function (Blueprint $table) {
            $table->id();
            // 'platform_default' / 'plan' / 'tenant' — resolved most-specific-wins
            // (CENTRAL_SPECS.md §3G).
            $table->string('scope_type');
            // A plan_code or tenant_id depending on scope_type; null for platform_default.
            $table->string('scope_id')->nullable();
            $table->json('reminder_offsets_days');
            $table->unsignedSmallInteger('cutoff_days_after_due');
            $table->string('cutoff_action')->default('read_only');
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_dunning_policies');
    }
};
