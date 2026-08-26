<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee Payroll Profile & Extensions:
 * - employee_payroll_profiles (tax status, NPWP, BPJS, salary structure)
 * - employee_bank_accounts
 * - employee_recurring_deductions
 * - employee_loans
 * - reimbursement_claims
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Employee Payroll Profiles
        Schema::create('PAYROLL.employee_payroll_profiles', function (Blueprint $table) {
            $table->id(); // maps 1:1 or FK to HCM.employees.id
            $table->foreignId('employee_id')->unique()->references('id')->on('HCM.employees')->cascadeOnDelete();
            $table->foreignId('payroll_group_id')->nullable()->references('id')->on('PAYROLL.payroll_groups')->nullOnDelete();
            $table->foreignId('salary_structure_id')->nullable()->references('id')->on('PAYROLL.salary_structures')->nullOnDelete();
            $table->string('ptkp_status_code', 20)->default('TK/0');
            $table->string('npwp_number', 30)->nullable();
            $table->boolean('has_npwp')->default(true);
            $table->string('bpjs_kesehatan_no', 30)->nullable();
            $table->string('bpjs_ketenagakerjaan_no', 30)->nullable();
            $table->foreignId('jkk_risk_category_id')->nullable()->references('id')->on('PAYROLL.jkk_risk_categories')->nullOnDelete();
            $table->boolean('is_tax_borne_by_company')->default(false); // Gross vs Gross-up/Net
            $table->string('proration_rule', 30)->default('work_days'); // work_days, calendar_days, none
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Employee Bank Accounts
        Schema::create('PAYROLL.employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->references('id')->on('HCM.employees')->cascadeOnDelete();
            $table->foreignId('bank_master_id')->nullable()->references('id')->on('PAYROLL.bank_master')->nullOnDelete();
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->string('account_holder_name', 150);
            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 3. Employee Recurring Deductions
        Schema::create('PAYROLL.employee_recurring_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->references('id')->on('HCM.employees')->cascadeOnDelete();
            $table->foreignId('payroll_component_id')->references('id')->on('PAYROLL.payroll_components')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('start_period', 7); // YYYY-MM
            $table->string('end_period', 7)->nullable(); // YYYY-MM
            $table->string('status', 30)->default('active'); // active, paused, completed
            $table->timestamps();
        });

        // 4. Employee Loans
        Schema::create('PAYROLL.employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->references('id')->on('HCM.employees')->cascadeOnDelete();
            $table->foreignId('loan_type_id')->references('id')->on('PAYROLL.loan_types')->cascadeOnDelete();
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('monthly_installment', 15, 2);
            $table->decimal('remaining_balance', 15, 2);
            $table->unsignedSmallInteger('tenure_months');
            $table->string('status', 30)->default('active'); // active, paid_off, written_off
            $table->timestamps();
        });

        // 5. Reimbursement Claims
        Schema::create('PAYROLL.reimbursement_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->references('id')->on('HCM.employees')->cascadeOnDelete();
            $table->foreignId('reimbursement_category_id')->references('id')->on('PAYROLL.reimbursement_categories')->cascadeOnDelete();
            $table->date('claim_date');
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->string('receipt_attachment_url', 255)->nullable();
            $table->string('status', 30)->default('pending'); // pending, approved, rejected, paid
            $table->foreignId('reviewed_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PAYROLL.reimbursement_claims');
        Schema::dropIfExists('PAYROLL.employee_loans');
        Schema::dropIfExists('PAYROLL.employee_recurring_deductions');
        Schema::dropIfExists('PAYROLL.employee_bank_accounts');
        Schema::dropIfExists('PAYROLL.employee_payroll_profiles');
    }
};
