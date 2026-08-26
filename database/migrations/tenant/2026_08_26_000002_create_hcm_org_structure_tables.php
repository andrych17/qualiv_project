<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HCM module — §3C Org units, jobs, positions, shifts, and regional minimum wages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('HCM.org_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_org_unit_id')->nullable()->constrained('HCM.org_units')->nullOnDelete();
            $table->string('name', 150);
            $table->unsignedBigInteger('accounting_cost_center_id')->nullable(); // informational only
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('HCM.jobs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('title', 150);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('HCM.positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('HCM.jobs');
            $table->foreignId('org_unit_id')->constrained('HCM.org_units');
            $table->foreignId('reports_to_position_id')->nullable()->constrained('HCM.positions')->nullOnDelete();
            $table->integer('headcount_cap')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('reports_to_position_id');
        });

        Schema::create('HCM.shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('break_minutes')->default(0);
            $table->boolean('is_active')->default(true);
        });

        Schema::create('HCM.regional_minimum_wages', function (Blueprint $table) {
            $table->id();
            $table->string('region_code', 30);
            $table->string('region_name', 150);
            $table->date('effective_date');
            $table->decimal('monthly_wage_amount', 14, 2);

            $table->unique(['region_code', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('HCM.regional_minimum_wages');
        Schema::dropIfExists('HCM.shifts');
        Schema::dropIfExists('HCM.positions');
        Schema::dropIfExists('HCM.jobs');
        Schema::dropIfExists('HCM.org_units');
    }
};
