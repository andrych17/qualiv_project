<?php

namespace App\Modules\HCM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmploymentContract extends Model
{
    protected $table = 'HCM.employment_contracts';

    public const TYPE_PKWT = 'PKWT';

    public const TYPE_PKWTT = 'PKWTT';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUS_RENEWED = 'renewed';

    protected $fillable = [
        'employee_id',
        'contract_type',
        'start_date',
        'end_date',
        'base_salary',
        'work_location',
        'probation_end_date',
        'status',
        'renewed_from_contract_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'probation_end_date' => 'date',
        'base_salary' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(EmploymentContract::class, 'renewed_from_contract_id');
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(EmploymentContract::class, 'renewed_from_contract_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['search'] ?? null, function ($query, $search) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('full_name', 'ilike', '%'.$search.'%')
                    ->orWhere('employee_no', 'ilike', '%'.$search.'%');
            });
        })->when($filters['contract_type'] ?? null, function ($query, $type) {
            $query->where('contract_type', $type);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['employee_id'] ?? null, function ($query, $employeeId) {
            $query->where('employee_id', $employeeId);
        });
    }
}
