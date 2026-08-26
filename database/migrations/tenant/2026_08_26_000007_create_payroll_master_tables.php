<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master tables for Payroll module:
 * - jkk_risk_categories
 * - payroll_calendars
 * - payroll_components
 * - grades
 * - deduction_rule_configs
 * - loan_types
 * - reimbursement_categories
 * - bank_master
 * - salary_structures & salary_structure_components
 * - payroll_groups
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. JKK Risk Categories
        Schema::create('PAYROLL.jkk_risk_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->decimal('employer_rate', 6, 4)->default(0.0024);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Payroll Calendars
        Schema::create('PAYROLL.payroll_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('pay_frequency', 30)->default('monthly'); // monthly, bi_weekly, weekly
            $table->unsignedSmallInteger('cutoff_day')->default(25);
            $table->unsignedSmallInteger('pay_day')->default(28);
            $table->boolean('shift_earlier_on_holiday')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Payroll Components
        Schema::create('PAYROLL.payroll_components', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->string('type', 30); // earning, deduction
            $table->string('category', 50); // fixed, formula, statutory, variable_input
            $table->string('calculation_basis', 50)->default('flat'); // flat, hourly, daily, percent_of_basic
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_bpjs_basis')->default(true);
            $table->string('gl_account_code', 50)->nullable();
            $table->boolean('is_system_defined')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 4. Grades
        Schema::create('PAYROLL.grades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->decimal('min_salary', 15, 2)->default(0);
            $table->decimal('max_salary', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 5. Deduction Rule Configs
        Schema::create('PAYROLL.deduction_rule_configs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('deduction_type', 50); // statutory, loan, disciplinary, voluntary
            $table->string('insufficient_funds_behavior', 50)->default('cap_to_net'); // cap_to_net, carry_forward, error
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 6. Loan Types
        Schema::create('PAYROLL.loan_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->string('interest_method', 30)->default('flat_zero'); // flat_zero, effective_annual
            $table->decimal('max_loan_limit', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 7. Reimbursement Categories
        Schema::create('PAYROLL.reimbursement_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->decimal('max_claim_amount', 15, 2)->default(0);
            $table->boolean('requires_receipt')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 8. Bank Master
        Schema::create('PAYROLL.bank_master', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('file_format', 50)->default('bca_payroll_csv'); // bca_payroll_csv, mandiri_mcm_csv, bri_csv, bni_csv
            $table->jsonb('template_spec')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 9. Salary Structures
        Schema::create('PAYROLL.salary_structures', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('grade_id')->nullable()->references('id')->on('PAYROLL.grades')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 10. Salary Structure Components
        Schema::create('PAYROLL.salary_structure_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->references('id')->on('PAYROLL.salary_structures')->cascadeOnDelete();
            $table->foreignId('payroll_component_id')->references('id')->on('PAYROLL.payroll_components')->cascadeOnDelete();
            $table->decimal('default_amount', 15, 2)->default(0);
            $table->text('formula_expression')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 11. Payroll Groups
        Schema::create('PAYROLL.payroll_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->foreignId('payroll_calendar_id')->nullable()->references('id')->on('PAYROLL.payroll_calendars')->nullOnDelete();
            $table->foreignId('default_salary_structure_id')->nullable()->references('id')->on('PAYROLL.salary_structures')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PAYROLL.payroll_groups');
        Schema::dropIfExists('PAYROLL.salary_structure_components');
        Schema::dropIfExists('PAYROLL.salary_structures');
        Schema::dropIfExists('PAYROLL.bank_master');
        Schema::dropIfExists('PAYROLL.reimbursement_categories');
        Schema::dropIfExists('PAYROLL.loan_types');
        Schema::dropIfExists('PAYROLL.deduction_rule_configs');
        Schema::dropIfExists('PAYROLL.grades');
        Schema::dropIfExists('PAYROLL.payroll_components');
        Schema::dropIfExists('PAYROLL.payroll_calendars');
        Schema::dropIfExists('PAYROLL.jkk_risk_categories');
    }
};
