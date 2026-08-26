<?php

namespace App\Modules\HCM\Models;

use App\Modules\Payroll\Models\EmployeePayrollProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Employee extends Model
{
    protected $table = 'HCM.employees';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_ON_LEAVE,
        self::STATUS_SUSPENDED,
        self::STATUS_TERMINATED,
    ];

    protected $fillable = [
        'uuid',
        'employee_no',
        'full_name',
        'date_of_birth',
        'gender',
        'nik',
        'npwp',
        'bpjs_kesehatan_no',
        'bpjs_ketenagakerjaan_no',
        'address',
        'marital_status',
        'dependents_count',
        'religion',
        'hire_date',
        'employment_status',
        'position_id',
        'bank_name',
        'bank_account_no',
        'bank_account_holder_name',
        'linked_partner_id',
        'termination_date',
        'termination_reason',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'hire_date' => 'date',
        'termination_date' => 'date',
        'dependents_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            if (empty($employee->uuid)) {
                $employee->uuid = (string) Str::uuid();
            }
        });
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function positionHistories(): HasMany
    {
        return $this->hasMany(EmployeePositionHistory::class, 'employee_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'employee_id');
    }

    public function currentContract(): HasOne
    {
        return $this->hasOne(EmploymentContract::class, 'employee_id')
            ->where('status', EmploymentContract::STATUS_ACTIVE)
            ->latestOfMany('start_date');
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class, 'employee_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'employee_id');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'employee_id');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'employee_id');
    }

    public function payrollProfile(): HasOne
    {
        return $this->hasOne(EmployeePayrollProfile::class, 'employee_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', '%'.$search.'%')
                    ->orWhere('employee_no', 'ilike', '%'.$search.'%')
                    ->orWhere('nik', 'ilike', '%'.$search.'%');
            });
        })->when($filters['employment_status'] ?? null, function ($query, $status) {
            $query->where('employment_status', $status);
        })->when($filters['position_id'] ?? null, function ($query, $positionId) {
            $query->where('position_id', $positionId);
        })->when($filters['org_unit_id'] ?? null, function ($query, $orgUnitId) {
            $query->whereHas('position', function ($q) use ($orgUnitId) {
                $q->where('org_unit_id', $orgUnitId);
            });
        });
    }
}
