<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class PackListLine extends Model
{
    protected $table = 'INVENTORY.pack_list_lines';

    public $timestamps = false;

    protected $fillable = ['pack_list_id', 'pick_list_line_id', 'product_id', 'batch_id', 'serial_id', 'qty'];

    protected $casts = [
        'qty' => 'decimal:4',
    ];

    public function packList()
    {
        return $this->belongsTo(PackList::class);
    }

    public function pickListLine()
    {
        return $this->belongsTo(PickListLine::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function serial()
    {
        return $this->belongsTo(StockSerial::class);
    }
}
