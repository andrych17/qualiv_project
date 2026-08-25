<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GoodsIssue extends Model
{
    protected $table = 'INVENTORY.goods_issues';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const REASON_CONSUMPTION = 'consumption';

    public const REASON_SAMPLE = 'sample';

    public const REASON_WRITE_OFF_PENDING = 'write_off_pending_adjustment_review';

    protected $fillable = [
        'uuid', 'warehouse_id', 'issue_date', 'subject_type', 'subject_id',
        'reason', 'status', 'posted_at', 'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (GoodsIssue $issue) {
            if (empty($issue->uuid)) {
                $issue->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        })->when($filters['warehouse_id'] ?? null, function ($query, $warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        });
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsIssueLine::class);
    }
}
