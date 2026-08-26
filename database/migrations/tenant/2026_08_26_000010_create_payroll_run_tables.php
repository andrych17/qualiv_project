<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Run Processing & Payslip storage:
 * - payroll_runs (header batch run)
 * - payroll_run_lines (employee payslip summary row)
 * - payroll_run_line_details (itemized payslip component line items)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Payroll Runs (Header Batch)
        Schema::create('PAYROLL.payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('run_number', 50)->unique();
            $table->foreignId('payroll_group_id')->nullable()->references('id')->on('PAYROLL.payroll_groups')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('run_type', 30)->default('regular'); // regular, off_cycle, thr, bonus, severance
            $table->string('status', 30)->default('draft'); // draft, calculating, calculated, approved, paid, locked, cancelled

            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->decimal('total_tax_pph21', 15, 2)->default(0);
            $table->decimal('total_bpjs_employer', 15, 2)->default(0);
            $table->decimal('total_bpjs_employee', 15, 2)->default(0);

            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->references('id')->on('users')->nullOnDelete();

            $table->foreignId('approved_by')->nullable()->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        // 2. Payroll Run Lines (Employee Payslip)
        Schema::create('PAYROLL.payroll_run_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->references('id')->on('PAYROLL.payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->references('id')->on('HCM.employees')->cascadeOnDelete();

            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('taxable_earnings', 15, 2)->default(0);
            $table->decimal('non_taxable_earnings', 15, 2)->default(0);
            $table->decimal('gross_total', 15, 2)->default(0);

            $table->decimal('bpjs_kesehatan_employer', 15, 2)->default(0);
            $table->decimal('bpjs_kesehatan_employee', 15, 2)->default(0);
            $table->decimal('bpjs_tk_employer', 15, 2)->default(0);
            $table->decimal('bpjs_tk_employee', 15, 2)->default(0);

            $table->decimal('pph21_amount', 15, 2)->default(0);
            $table->decimal('other_deductions', 15, 2)->default(0);

            $table->decimal('net_total', 15, 2)->default(0);
            $table->decimal('take_home_pay', 15, 2)->default(0);

            $table->string('ptkp_status_code', 20)->default('TK/0');
            $table->string('ter_category', 5)->nullable();
            $table->decimal('ter_rate_percentage', 6, 4)->default(0);

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        // 3. Payroll Run Line Details (Itemized Component Lines)
        Schema::create('PAYROLL.payroll_run_line_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_line_id')->references('id')->on('PAYROLL.payroll_run_lines')->cascadeOnDelete();
            $table->foreignId('payroll_component_id')->nullable()->references('id')->on('PAYROLL.payroll_components')->nullOnDelete();
            $table->string('component_name', 150);
            $table->string('type', 30); // earning, deduction
            $table->string('category', 50); // fixed, formula, statutory, variable_input
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PAYROLL.payroll_run_line_details');
        Schema::dropIfExists('PAYROLL.payroll_run_lines');
        Schema::dropIfExists('PAYROLL.payroll_runs');
    }
};
