<?php

namespace App\Modules\POS\Models;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockSerial;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS_SPECS.md §3F, §3K, §3N, §3O / §4 — POS Transaction Line.
 */
class PosTxnLine extends Model
{
    protected $table = 'POS.pos_txn_lines';

    public $timestamps = false;

    public const KDS_NEW = 'new';

    public const KDS_PREPARING = 'preparing';

    public const KDS_READY = 'ready';

    public const KDS_SERVED = 'served';

    protected $fillable = [
        'txn_id',
        'line_no',
        'product_id',
        'is_open_item',
        'description',
        'uom_code',
        'qty',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'line_total',
        'batch_id',
        'serial_id',
        'kds_station_id',
        'course',
        'seat_number',
        'special_instruction',
        'kitchen_note',
        'kds_status',
        'inventory_posted',
    ];

    protected $casts = [
        'line_no' => 'integer',
        'is_open_item' => 'boolean',
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'seat_number' => 'integer',
        'inventory_posted' => 'boolean',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTxnHdr::class, 'txn_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(StockSerial::class, 'serial_id');
    }

    public function kdsStation(): BelongsTo
    {
        return $this->belongsTo(PosKdsStation::class, 'kds_station_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(PosTxnLineModifier::class, 'txn_line_id');
    }

    public function kdsEvents(): HasMany
    {
        return $this->hasMany(PosKdsTicketEvent::class, 'txn_line_id')->orderBy('occurred_at');
    }
}
