<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CommissionSettlement extends Model
{
    protected $table = 'SALES.comm_settlements';

    public $timestamps = false;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
    ];

    protected $fillable = [
        'uuid',
        'rep_id',
        'period_start',
        'period_end',
        'status',
        'total_amount',
        'currency',
        'wne_workflow_instance_id',
        'approved_by',
        'approved_at',
        'paid_at',
        'created_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function rep()
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines()
    {
        return $this->hasMany(CommissionSettlementLine::class, 'settlement_id');
    }
}
