<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class TransferLine extends Model
{
    protected $table = 'INVENTORY.transfer_lines';

    public $timestamps = false;

    protected $fillable = ['transfer_id', 'product_id', 'batch_id', 'qty', 'uom_id', 'serial_numbers'];

    protected $casts = [
        'qty' => 'decimal:4',
        'serial_numbers' => 'array',
    ];

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
