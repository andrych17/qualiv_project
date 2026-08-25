<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Append-only, immutable — the single source of truth for every quantity change (§4). */
class StockLedger extends Model
{
    protected $table = 'INVENTORY.stock_ledger';

    const UPDATED_AT = null;

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_ISSUE = 'issue';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'uuid', 'product_id', 'warehouse_id', 'location_id', 'batch_id', 'movement_type',
        'qty', 'unit_cost', 'total_value', 'subject_type', 'subject_id', 'movement_date', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_cost' => 'decimal:6',
        'total_value' => 'decimal:4',
        'movement_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockLedger $ledger) {
            if (empty($ledger->uuid)) {
                $ledger->uuid = (string) Str::uuid();
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
