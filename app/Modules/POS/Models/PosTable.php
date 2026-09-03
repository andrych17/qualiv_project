<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * POS_SPECS.md §3M / §4 — Restaurant Dining Table.
 */
class PosTable extends Model
{
    protected $table = 'POS.pos_tables';
    public $timestamps = false;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_OCCUPIED = 'occupied';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CLEANING = 'cleaning';

    protected $fillable = [
        'floor_id',
        'code',
        'seat_count',
        'pos_x',
        'pos_y',
        'status',
    ];

    protected $casts = [
        'seat_count' => 'integer',
        'pos_x' => 'integer',
        'pos_y' => 'integer',
    ];

    public function floor(): BelongsTo
    {
        return $this->belongsTo(PosFloor::class, 'floor_id');
    }

    public function activeTransaction(): HasOne
    {
        return $this->hasOne(PosTxnHdr::class, 'table_id')
            ->whereIn('status', [PosTxnHdr::STATUS_DRAFT, PosTxnHdr::STATUS_PARKED])
            ->latest('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PosTxnHdr::class, 'table_id');
    }

    public function scopeFilter(Builder $query, array $filters): void
    {
        $query->when($filters['floor_id'] ?? null, function ($query, $floorId) {
            $query->where('floor_id', $floorId);
        })->when($filters['status'] ?? null, function ($query, $status) {
            $query->where('status', $status);
        });
    }
}
