<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statutory rates & config tables for Indonesian Payroll:
 * - ptkp_statuses (TK/0..K/3 -> TER A/B/C mapping)
 * - pph21_ter_rates (Monthly TER brackets for A, B, C per PP 58/2023)
 * - bpjs_config (KES, JHT, JP, JKK, JKM rates & caps)
 * - severance_rule_matrices (UU Cipta Kerja severance & reward calculation)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. PTKP Statuses
        Schema::create('PAYROLL.ptkp_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // TK/0, TK/1, TK/2, TK/3, K/0, K/1, K/2, K/3, K/I/0..
            $table->string('description', 100);
            $table->decimal('annual_ptkp_amount', 15, 2);
            $table->string('ter_category', 5); // 'A', 'B', 'C'
            $table->date('effective_date')->default('2024-01-01');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. PPh 21 TER Rates (PP 58/2023 & PMK 168/2023)
        Schema::create('PAYROLL.pph21_ter_rates', function (Blueprint $table) {
            $table->id();
            $table->string('ter_category', 5); // 'A', 'B', 'C'
            $table->decimal('min_gross_monthly', 15, 2)->default(0);
            $table->decimal('max_gross_monthly', 15, 2)->nullable(); // null means infinity
            $table->decimal('rate_percentage', 6, 4); // e.g. 0.0000 to 0.3400
            $table->date('effective_date')->default('2024-01-01');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['ter_category', 'min_gross_monthly', 'max_gross_monthly']);
        });

        // 3. BPJS Config
        Schema::create('PAYROLL.bpjs_config', function (Blueprint $table) {
            $table->id();
            $table->string('program_code', 30)->unique(); // KES, JHT, JP, JKK, JKM
            $table->string('name', 100);
            $table->decimal('employer_rate', 6, 4); // e.g. 0.0400 (4%)
            $table->decimal('employee_rate', 6, 4); // e.g. 0.0100 (1%)
            $table->decimal('wage_cap', 15, 2)->nullable(); // e.g. 12000000 for KES, 10042300 for JP
            $table->date('effective_date')->default('2024-01-01');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 4. Severance Rule Matrices (PP 35/2021 & UU Cipta Kerja)
        Schema::create('PAYROLL.severance_rule_matrices', function (Blueprint $table) {
            $table->id();
            $table->string('term_reason', 50); // resignation, end_of_contract, redundancy, retirement, death, disciplinary
            $table->unsignedSmallInteger('years_of_service_min')->default(0);
            $table->unsignedSmallInteger('years_of_service_max')->nullable();
            $table->decimal('severance_months', 5, 2)->default(0); // PMTK pengali
            $table->decimal('reward_months', 5, 2)->default(0); // UPMK masa kerja
            $table->decimal('compensation_rate', 5, 2)->default(0.15); // UPH 15% dari pesangon+UPMK
            $table->date('effective_date')->default('2021-02-02');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PAYROLL.severance_rule_matrices');
        Schema::dropIfExists('PAYROLL.bpjs_config');
        Schema::dropIfExists('PAYROLL.pph21_ter_rates');
        Schema::dropIfExists('PAYROLL.ptkp_statuses');
    }
};
