<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Adjustment extends Model
{
    protected $table = 'INVENTORY.adjustments';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    /** Reserved, unreachable — see the migration's docblock on why §3G's WNE approval routing isn't wired yet. */
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    protected $fillable = [
        'uuid', 'warehouse_id', 'location_id', 'adjustment_date', 'reason_id',
        'reference', 'status', 'posted_at', 'created_by',
    ];

    protected $casts = [
        'adjustment_date' => 'date',
        'posted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Adjustment $adjustment) {
            if (empty($adjustment->uuid)) {
                $adjustment->uuid = (string) Str::uuid();
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

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function reason()
    {
        return $this->belongsTo(AdjustmentReason::class, 'reason_id');
    }

    public function lines()
    {
        return $this->hasMany(AdjustmentLine::class);
    }
}
