<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HCM module — §3F Leave types and leave policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('HCM.leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('HCM.leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained('HCM.leave_types')->cascadeOnDelete();
            $table->string('contract_type', 10)->nullable(); // PKWT|PKWTT, null = both
            $table->decimal('entitlement_days_per_year', 5, 2);
            $table->string('accrual_method', 20)->default('annual_grant'); // annual_grant|monthly_accrual
            $table->decimal('carry_over_max_days', 5, 2)->default(0);
            $table->integer('carry_over_expiry_months')->nullable();
            $table->boolean('is_paid')->default(true);

            $table->unique(['leave_type_id', 'contract_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('HCM.leave_policies');
        Schema::dropIfExists('HCM.leave_types');
    }
};
