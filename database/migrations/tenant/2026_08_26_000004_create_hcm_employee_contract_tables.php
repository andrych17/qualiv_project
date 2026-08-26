<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HCM module — §3B Employee Master & §3D Employment Contracts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('HCM.employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique();
            $table->string('employee_no', 30)->unique();
            $table->string('full_name', 255);
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable(); // male|female|other
            $table->char('nik', 16)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('bpjs_kesehatan_no', 30)->nullable();
            $table->string('bpjs_ketenagakerjaan_no', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('marital_status', 15)->nullable(); // single|married|divorced|widowed
            $table->integer('dependents_count')->default(0);
            $table->string('religion', 30)->nullable(); // used for THR timing
            $table->date('hire_date');
            $table->string('employment_status', 15)->default('active'); // active|on_leave|suspended|terminated
            $table->foreignId('position_id')->nullable()->constrained('HCM.positions')->nullOnDelete();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_no', 50)->nullable();
            $table->string('bank_account_holder_name', 150)->nullable();
            $table->unsignedBigInteger('linked_partner_id')->nullable(); // informational link to CRM.partners
            $table->date('termination_date')->nullable();
            $table->string('termination_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['position_id', 'employment_status']);
            $table->index('employment_status');
        });

        Schema::create('HCM.employee_position_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('HCM.employees')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('HCM.positions');
            $table->date('effective_from');
            $table->date('effective_to')->nullable(); // null = current
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('HCM.employment_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('HCM.employees')->cascadeOnDelete();
            $table->string('contract_type', 10); // PKWT|PKWTT
            $table->date('start_date');
            $table->date('end_date')->nullable(); // required for PKWT
            $table->decimal('base_salary', 14, 2);
            $table->string('work_location', 150)->nullable();
            $table->date('probation_end_date')->nullable(); // PKWTT only
            $table->string('status', 15)->default('active'); // active|expired|terminated|renewed
            $table->foreignId('renewed_from_contract_id')->nullable()->constrained('HCM.employment_contracts')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('HCM.employment_contracts');
        Schema::dropIfExists('HCM.employee_position_history');
        Schema::dropIfExists('HCM.employees');
    }
};
