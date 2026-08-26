<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HCM module — §3E Time & Attendance, §3F Leave Management.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('HCM.shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('HCM.employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('HCM.shifts');
            $table->date('work_date');

            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('HCM.attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('HCM.employees')->cascadeOnDelete();
            $table->timestampTz('clock_in_at')->nullable();
            $table->timestampTz('clock_out_at')->nullable();
            $table->string('source', 20)->default('web'); // web|mobile|biometric
            $table->string('exception_flag', 20)->nullable(); // on_time|late|absent
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['employee_id', 'created_at']);
        });

        Schema::create('HCM.attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_log_id')->constrained('HCM.attendance_logs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('HCM.employees')->cascadeOnDelete();
            $table->timestampTz('requested_clock_in_at')->nullable();
            $table->timestampTz('requested_clock_out_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('HCM.leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('HCM.employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('HCM.leave_types');
            $table->smallInteger('period_year');
            $table->decimal('entitled_days', 5, 2)->default(0);
            $table->decimal('used_days', 5, 2)->default(0);
            $table->decimal('carried_over_days', 5, 2)->default(0);

            $table->unique(['employee_id', 'leave_type_id', 'period_year']);
        });

        Schema::create('HCM.leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('HCM.employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('HCM.leave_types');
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|cancelled
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('HCM.leave_requests');
        Schema::dropIfExists('HCM.leave_balances');
        Schema::dropIfExists('HCM.attendance_corrections');
        Schema::dropIfExists('HCM.attendance_logs');
        Schema::dropIfExists('HCM.shift_assignments');
    }
};
