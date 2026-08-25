<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Transfer extends Model
{
    protected $table = 'INVENTORY.transfers';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'uuid', 'source_warehouse_id', 'source_location_id', 'destination_warehouse_id', 'destination_location_id',
        'transfer_date', 'status', 'posted_at', 'completed_at', 'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'posted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Transfer $transfer) {
            if (empty($transfer->uuid)) {
                $transfer->uuid = (string) Str::uuid();
            }
        });
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }

    public function sourceWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function sourceLocation()
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function destinationLocation()
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function lines()
    {
        return $this->hasMany(TransferLine::class);
    }
}
