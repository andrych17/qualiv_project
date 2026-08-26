<?php

namespace App\Modules\Payroll\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use HasUuids;

    protected $table = 'PAYROLL.payroll_runs';

    public const TYPE_REGULAR = 'regular';

    public const TYPE_OFF_CYCLE = 'off_cycle';

    public const TYPE_THR = 'thr';

    public const TYPE_BONUS = 'bonus';

    public const TYPE_SEVERANCE = 'severance';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_CALCULATING = 'calculating';

    public const STATUS_CALCULATED = 'calculated';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'run_number',
        'payroll_group_id',
        'period_start',
        'period_end',
        'pay_date',
        'run_type',
        'status',
        'total_gross',
        'total_deductions',
        'total_net',
        'total_tax_pph21',
        'total_bpjs_employer',
        'total_bpjs_employee',
        'is_locked',
        'locked_at',
        'locked_by',
        'approved_by',
        'approved_at',
        'paid_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'pay_date' => 'date',
        'total_gross' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net' => 'decimal:2',
        'total_tax_pph21' => 'decimal:2',
        'total_bpjs_employer' => 'decimal:2',
        'total_bpjs_employee' => 'decimal:2',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function payrollGroup(): BelongsTo
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class, 'payroll_run_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
