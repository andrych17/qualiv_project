<?php

namespace App\Modules\Purchase\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PurRequisitionHdr extends Model
{
    protected $table = 'PURCHASE.pur_requisition_hdrs';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'uuid',
        'pr_no',
        'requester_id',
        'cost_center_id',
        'needed_by',
        'subject_type',
        'subject_id',
        'status',
        'estimated_total',
        'budget_warning',
        'duplicate_warning',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'needed_by' => 'date',
        'estimated_total' => 'decimal:2',
        'budget_warning' => 'boolean',
        'duplicate_warning' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function costCenter()
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function department()
    {
        return $this->costCenter();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(PurRequisitionLine::class, 'pr_id')->orderBy('line_no');
    }

    public function orders()
    {
        return $this->hasMany(PurOrderHdr::class, 'pr_id');
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
